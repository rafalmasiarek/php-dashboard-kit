<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use rafalmasiarek\DashboardKit\Log\RequestLogSanitizer;

/**
 * PSR-15 middleware that writes one access log entry per request.
 *
 * Uses $_SERVER['REQUEST_TIME_FLOAT'] as the start timestamp — set by nginx/Apache
 * when the connection is accepted, before PHP starts executing. The measured duration
 * therefore includes PHP startup, autoloading, container setup, and the full stack.
 *
 * Logged fields:
 *   method, path, status, duration_ms — always present
 *   query.*                           — GET parameters (sanitized)
 *   body.*                            — POST/form parameters (sanitized)
 *   referer                           — HTTP Referer header when present
 *
 * Sensitive fields (password, token, …) are masked as "***".
 * Identity fields (email, login, …) are AES-256-CBC encrypted with APP_KEY.
 *
 * Log output example:
 *   [level=info] [channel=app] access method=POST path=/login status=302 duration_ms=45.2
 *     body.email=enc:abc123 body.password=*** referer=https://example.com req.ip=1.2.3.4 req.id=…
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class RequestTimingMiddleware implements MiddlewareInterface
{
    /**
     * @param LoggerInterface     $logger    Logger to write access entries to.
     * @param RequestLogSanitizer $sanitizer Sanitizes query/body params before logging.
     */
    public function __construct(
        private readonly LoggerInterface     $logger,
        private readonly RequestLogSanitizer $sanitizer,
    ) {
    }

    /**
     * Handles the request and logs one access entry after the response is produced.
     *
     * @param  ServerRequestInterface  $request
     * @param  RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = (float) ($request->getServerParams()['REQUEST_TIME_FLOAT'] ?? \microtime(true));

        $response = $handler->handle($request);

        $ms = \round((\microtime(true) - $start) * 1000, 2);

        $ctx = [
            'method'      => $request->getMethod(),
            'path'        => $request->getUri()->getPath(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => $ms,
        ];

        $query = $this->sanitizer->sanitize((array) $request->getQueryParams());
        foreach ($query as $k => $v) {
            $ctx['query.' . $k] = $v;
        }

        $body = $request->getParsedBody();
        if (\is_array($body) && !empty($body)) {
            foreach ($this->sanitizer->sanitize($body) as $k => $v) {
                $ctx['body.' . $k] = $v;
            }
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $ctx['referer'] = $referer;
        }

        $this->logger->info('access', $ctx);

        return $response;
    }
}
