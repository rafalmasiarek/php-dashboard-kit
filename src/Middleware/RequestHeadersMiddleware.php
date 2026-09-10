<?php

namespace rafalmasiarek\DashboardKit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rafalmasiarek\DashboardKit\Log\RequestHeadersProcessor;

/**
 * PSR-15 middleware that boots all registered RequestHeadersProcessor instances.
 *
 * Added to the Slim stack automatically by Dashboard when any logging channel
 * has request_headers configured (globally or per-channel).
 *
 * @package rafalmasiarek\DashboardKit\Middleware
 */
class RequestHeadersMiddleware implements MiddlewareInterface
{
    /** @var RequestHeadersProcessor[] */
    private array $processors;

    /**
     * @param RequestHeadersProcessor ...$processors One processor per channel that has request_headers configured.
     */
    public function __construct(RequestHeadersProcessor ...$processors)
    {
        $this->processors = $processors;
    }

    /**
     * Boots all processors with the current request then passes control downstream.
     *
     * @param  ServerRequestInterface  $request PSR-7 server request.
     * @param  RequestHandlerInterface $handler Next middleware or route handler.
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach ($this->processors as $processor) {
            $processor->boot($request);
        }

        return $handler->handle($request);
    }
}
