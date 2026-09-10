<?php

namespace rafalmasiarek\DashboardKit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rafalmasiarek\Csrf\Csrf;
use Slim\Exception\HttpException;

/**
 * Validates CSRF tokens on state-changing HTTP methods.
 *
 * Expects a hidden field named _csrf in the request body. The token is
 * generated in Twig templates via {{ csrf.generate() }} using the 'csrf'
 * Twig global. GET and HEAD requests are passed through without checking.
 *
 * @package rafalmasiarek\DashboardKit\Middleware
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param Csrf $csrf CSRF token service.
     */
    public function __construct(private readonly Csrf $csrf)
    {
    }

    /**
     * Validates the _csrf field for POST/PUT/PATCH/DELETE requests.
     *
     * For form submissions reads _csrf + _csrf_container from the parsed body.
     * For JSON fetch requests (Content-Type: application/json) falls back to
     * reading the same fields from the raw JSON body, also accepting the legacy
     * csrf_token field name used by admin-requests.js.
     *
     * Returns HTTP 419 when the token is missing or invalid.
     *
     * @param  ServerRequestInterface  $request Incoming request.
     * @param  RequestHandlerInterface $handler Next middleware in chain.
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body      = (array) $request->getParsedBody();
        $token     = $body['_csrf'] ?? null;
        $container = isset($body['_csrf_container']) ? (string) $body['_csrf_container'] : null;

        if ($token === null) {
            $ct = strtolower($request->getHeaderLine('Content-Type'));
            if (str_contains($ct, 'application/json')) {
                $json = json_decode((string) $request->getBody(), true);
                if (is_array($json)) {
                    $token     = $json['_csrf'] ?? $json['csrf_token'] ?? null;
                    $container = isset($json['_csrf_container']) ? (string) $json['_csrf_container'] : $container;
                }
            }
        }

        $valid = ($container !== null)
            ? $this->csrf->validateFor($container, $token)
            : $this->csrf->validate($token);

        if (!$valid) {
            throw new HttpException($request, 'CSRF token validation failed.', 419);
        }

        return $handler->handle($request);
    }
}
