<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ensures PHP session is started for every request.
 *
 * Must run before CsrfMiddleware so that $_SESSION is available when
 * CSRF tokens are validated or generated.
 *
 * @package rafalmasiarek\DashboardKit\Middleware
 */
class SessionMiddleware implements MiddlewareInterface
{
    /**
     * Starts the PHP session if not already active.
     *
     * @param  ServerRequestInterface  $request Incoming request.
     * @param  RequestHandlerInterface $handler Next middleware in chain.
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $handler->handle($request);
    }
}
