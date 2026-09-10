<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use rafalmasiarek\DashboardKit\Security\SecretRegistry;
use Slim\Exception\HttpException;
use Throwable;

/**
 * Slim 4 default error handler with content negotiation and secret redaction.
 *
 * - Returns a JSON envelope for API/CLI/AJAX requests (Accept: application/json,
 *   Content-Type: application/json, X-Requested-With: XMLHttpRequest, ?format=json, or CLI SAPI).
 * - Delegates HTML rendering to an optional callable for browser requests.
 * - Falls back to plain text when no HTML renderer is provided or it returns null.
 * - Redacts common secret patterns (tokens, passwords, API keys) from messages and traces.
 * - Shortens absolute file paths when displayErrorDetails is false (production).
 *
 * JSON envelope shape (always present, mirrors JsonHandler for consistency):
 *   { "status": "error", "code": <int>, "message": <string>, "data": {}, "errors": [] }
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class SafeErrorHandler
{
    /**
     * PSR-7 response factory.
     *
     * @var ResponseFactoryInterface
     */
    private ResponseFactoryInterface $responseFactory;

    /**
     * Optional PSR-3 logger for error entries.
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Optional HTML renderer callback.
     *
     * Signature: fn(int $status, string $message, bool $showDetails, ServerRequestInterface $request): ?ResponseInterface
     * Return null to fall through to plain-text fallback.
     *
     * @var callable|null
     */
    private $htmlRenderer;

    /**
     * Optional Whoops instance used as the HTML renderer in dev mode.
     *
     * When set, browser requests bypass $htmlRenderer and are served by Whoops.
     * Typed as object to avoid a hard dependency on filp/whoops at the class level
     * (it is a require-dev package — may not be installed in production).
     *
     * @var object|null
     */
    private ?object $whoops;

    /**
     * @param ResponseFactoryInterface $responseFactory
     * @param LoggerInterface|null     $logger       Optional PSR-3 logger; errors are logged at error/warning level.
     * @param callable|null            $htmlRenderer Optional HTML renderer for browser requests (Twig templates).
     * @param object|null              $whoops       Optional \Whoops\Run instance; when set, serves HTML via Whoops instead of $htmlRenderer.
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        ?LoggerInterface $logger = null,
        ?callable $htmlRenderer = null,
        ?object $whoops = null
    ) {
        $this->responseFactory = $responseFactory;
        $this->logger          = $logger;
        $this->htmlRenderer    = $htmlRenderer;
        $this->whoops          = $whoops;
    }

    /**
     * Invokable Slim default error handler.
     *
     * @param ServerRequestInterface $request
     * @param Throwable              $exception
     * @param bool                   $displayErrorDetails Whether to include stack traces.
     * @param bool                   $logErrors           Whether to log the error.
     * @param bool                   $logErrorDetails     Whether to include the exception in log context.
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $status       = $this->statusFromException($exception);
        $shortenPaths = !$displayErrorDetails;
        $message      = $status === 404 ? 'Not found.' : $this->safeMessage($exception->getMessage(), $shortenPaths);

        if ($logErrors && $this->logger !== null) {
            $logLevel   = $status >= 500 ? 'error' : 'warning';
            $context    = $logErrorDetails ? ['exception' => $exception] : [];
            $logMessage = SecretRegistry::redactValues($this->safeMessage($exception->getMessage(), false));
            $this->logger->$logLevel($logMessage, $context);
        }

        $errors = [];
        if ($displayErrorDetails) {
            $errors[] = [
                'file'  => $shortenPaths ? $this->shortenPath($exception->getFile()) : $exception->getFile(),
                'line'  => $exception->getLine(),
                'trace' => $this->safeTrace($exception, $shortenPaths),
            ];
        }

        // 1) JSON for API / CLI / AJAX
        if ($this->wantsJson($request)) {
            $payload = [
                'status'  => 'error',
                'code'    => $status,
                'message' => $message,
                'data'    => new \stdClass(),
                'errors'  => $errors,
            ];
            $json     = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $response = $this->responseFactory->createResponse($status);
            $response->getBody()->write(\is_string($json) ? $json : '{"status":"error","code":500,"message":"Internal Server Error","data":{},"errors":[]}');
            return $response->withHeader('Content-Type', 'application/json');
        }

        // 2) Whoops for dev HTML
        if ($this->whoops !== null && $this->wantsHtml($request)) {
            $output   = $this->whoops->handleException($exception);
            $response = $this->responseFactory->createResponse($status);
            $response->getBody()->write($output);
            return $response;
        }

        // 3) HTML via optional renderer callback
        if ($this->htmlRenderer !== null && $this->wantsHtml($request)) {
            $rendered = ($this->htmlRenderer)($status, $message, $displayErrorDetails, $request);
            if ($rendered instanceof ResponseInterface) {
                return $rendered;
            }
        }

        // 4) Plain-text fallback
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($message);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Map an exception to an HTTP status code.
     *
     * @param  Throwable $e
     * @return int
     */
    private function statusFromException(Throwable $e): int
    {
        $code = $e->getCode();
        if ($e instanceof HttpException && \is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }
        return (\is_int($code) && $code >= 400 && $code <= 599) ? $code : 500;
    }

    /**
     * Heuristics: return true when the client expects a JSON response.
     *
     * @param  ServerRequestInterface $request
     * @return bool
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            return true;
        }
        $accept = \strtolower($request->getHeaderLine('Accept'));
        $ctype  = \strtolower($request->getHeaderLine('Content-Type'));
        $xrw    = \strtolower($request->getHeaderLine('X-Requested-With'));
        $query  = $request->getQueryParams();

        if (isset($query['format']) && \strtolower((string) $query['format']) === 'json') {
            return true;
        }
        return \strpos($accept, 'application/json') !== false
            || \strpos($ctype,  'application/json') !== false
            || $xrw === 'xmlhttprequest';
    }

    /**
     * Heuristics: return true when the client expects an HTML response.
     *
     * @param  ServerRequestInterface $request
     * @return bool
     */
    private function wantsHtml(ServerRequestInterface $request): bool
    {
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            return false;
        }
        $accept = \strtolower($request->getHeaderLine('Accept'));
        return $accept === '' || \strpos($accept, 'text/html') !== false;
    }

    /**
     * Build a redacted stack trace; optionally shorten absolute paths.
     *
     * @param  Throwable $e
     * @param  bool      $shortenPaths
     * @return string[]
     */
    private function safeTrace(Throwable $e, bool $shortenPaths): array
    {
        $lines = \explode(\PHP_EOL, $e->getTraceAsString());
        $clean = [];
        foreach ($lines as $line) {
            $line    = $this->safeMessage($line, $shortenPaths);
            $clean[] = $shortenPaths ? $this->shortenPath($line) : $line;
        }
        return $clean;
    }

    /**
     * Redact known secret patterns from a string.
     *
     * Covers: ENV-style KEY=VALUE pairs, common query/body param names (token,
     * password, api_key, secret), and Basic-Auth credentials in URIs.
     * Optionally shortens absolute filesystem paths.
     *
     * @param  string $msg
     * @param  bool   $shortenPaths
     * @return string
     */
    private function safeMessage(string $msg, bool $shortenPaths): string
    {
        // ENV-like sensitive pairs: SECRET=value, API_KEY=value, etc.
        $msg = (string) \preg_replace(
            '/\b([A-Z0-9_]*?(?:SECRET|TOKEN|API[_-]?KEY|PASS(?:WORD)?|PWD)[A-Z0-9_]*)\s*[:=]\s*([^\s&]+)/i',
            '$1=****',
            $msg
        );

        // Common query/body param names
        foreach ([
            '/\b(token|access_token|refresh_token|id_token|auth_token)\s*[:=]\s*([^\s&]+)/i',
            '/\b(pass(?:word)?|pwd)\s*[:=]\s*([^\s&]+)/i',
            '/\b(api[_-]?key)\s*[:=]\s*([^\s&]+)/i',
            '/\b(secret)\s*[:=]\s*([^\s&]+)/i',
        ] as $pattern) {
            $msg = (string) \preg_replace($pattern, '$1=****', $msg);
        }

        // Basic auth in URIs: scheme://user:pass@host
        $msg = (string) \preg_replace_callback(
            '/([a-z][a-z0-9+.\-]*:\/\/)([^:@\s]+):([^@\s]+)@/i',
            static fn($m) => $m[1] . $m[2] . ':****@',
            $msg
        );

        $msg = SecretRegistry::redactValues($msg);

        return $shortenPaths ? $this->shortenPath($msg) : $msg;
    }

    /**
     * Collapse absolute filesystem paths to ".../filename.ext".
     *
     * @param  string $s
     * @return string
     */
    private function shortenPath(string $s): string
    {
        return (string) \preg_replace(
            '#(?<!\.)\\b([A-Za-z]:\\\\\\\\|/)(?:[^/\\\\\n]+[\\\\/])+([^/\\\\\n]+\\.(?:php|inc|phtml|js|ts|jsx|tsx|html))#',
            '.../$2',
            $s
        );
    }
}
