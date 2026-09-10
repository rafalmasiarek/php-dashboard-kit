<?php

namespace rafalmasiarek\DashboardKit\Middleware;

use AuthKit\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Redirects unauthenticated requests to the login page.
 *
 * @package rafalmasiarek\DashboardKit\Middleware
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param Auth   $auth               AuthKit authentication service.
     * @param string $dashboardUrlPrefix Full URL prefix for the dashboard (e.g. '/api/admin').
     *                                  Prepended to the /login redirect target.
     */
    public function __construct(
        private readonly Auth   $auth,
        private readonly string $dashboardUrlPrefix = '',
    ) {
    }

    /**
     * Passes the request through if authenticated, redirects to /login otherwise.
     *
     * Appends a ?from= query parameter so the login controller can redirect back
     * to the original URL after successful authentication.
     *
     * @param  ServerRequestInterface  $request Request instance.
     * @param  RequestHandlerInterface $handler Next handler in the chain.
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->auth->isLoggedIn()) {
            $uri  = $request->getUri();
            $from = $uri->getPath();
            if ($uri->getQuery() !== '') {
                $from .= '?' . $uri->getQuery();
            }
            $target = $this->dashboardUrlPrefix . '/login?from=' . \urlencode($from);
            return (new Response())->withHeader('Location', $target)->withStatus(302);
        }

        return $handler->handle($request);
    }
}
