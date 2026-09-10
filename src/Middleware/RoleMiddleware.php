<?php

namespace rafalmasiarek\DashboardKit\Middleware;

use AuthKit\Auth;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Enforces role-based access on top of authentication.
 *
 * Must be used after AuthMiddleware — assumes the user is already logged in.
 * Redirects to / with 403 status when the user's role is not in the allowed list.
 *
 * Module usage:
 *   'auth_required' => 'admin'
 *   'auth_required' => ['admin', 'editor']
 *
 * @package rafalmasiarek\DashboardKit\Middleware
 */
class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param ContainerInterface $container           PSR-11 container (Auth resolved lazily per request).
     * @param string[]           $roles               Allowed role values.
     * @param string             $dashboardUrlPrefix  Full URL prefix for dashboard routes (base_path + dashboard.prefix).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $roles,
        private readonly string $dashboardUrlPrefix = '',
    ) {
    }

    /**
     * Passes the request through if the current user's role is in the allowed list.
     *
     * @param  ServerRequestInterface  $request
     * @param  RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->container->get(Auth::class)->getUser();
        $role = $user?->get('role') ?? 'user';

        if (!in_array($role, $this->roles, true)) {
            return (new Response())->withHeader('Location', $this->dashboardUrlPrefix . '/')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
