# Middleware

Middleware wraps the request/response cycle. Each layer runs code before the next handler is called, then optionally after it returns — giving you a clean place for cross-cutting concerns: security headers, IP resolution, logging, rate limiting, etc.

Dashboard Kit is built on Slim 4, which implements the PSR-15 middleware standard. You can attach any PSR-15-compatible middleware with no framework-specific glue.

## How middleware executes

Middleware runs in **last-in, first-out** order. The last middleware added is the first to execute.

```
Request  →  [SecurityHeaders]  →  [RealIp]  →  [RequestId]  →  Handler
Response ←  [SecurityHeaders]  ←  [RealIp]  ←  [RequestId]  ←  Handler
```

Registered in `index.php` as:
```php
$dashboard->getApp()->add(new RequestIdMiddleware());   // runs 3rd in, 3rd out
$dashboard->getApp()->add(new RealIpMiddleware(...));   // runs 2nd in, 2nd out
$dashboard->getApp()->add(new SecurityHeadersMiddleware()); // runs 1st in, 1st out
```

---

## Class-based middleware (PSR-15)

Implement `Psr\Http\Server\MiddlewareInterface` with a single `process()` method.

```php
<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // --- before the handler ---
        $request = $request->withAttribute('my_key', 'my_value');

        $response = $handler->handle($request); // call the next layer

        // --- after the handler ---
        return $response->withHeader('X-Powered-By', 'MyApp');
    }
}
```

Key points:
- `$request` is immutable — use `withAttribute()` / `withHeader()` to produce a modified copy.
- `$response` is also immutable — same pattern.
- Call `$handler->handle($request)` exactly once and return its result (modified or not).
- Never call `$handler->handle()` to short-circuit — just return a response directly.

---

## Example 1 — Request ID middleware

Attaches a unique identifier to every request. Useful for correlating log entries across services. Honors an existing `X-Request-Id` header forwarded by a load balancer.

```php
<?php
// src/Middleware/RequestIdMiddleware.php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8));

        $response = $handler->handle(
            $request->withAttribute('request_id', $requestId)
        );

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
```

**Register:**
```php
// public/index.php
$dashboard->getApp()->add(new \App\Middleware\RequestIdMiddleware());
```

**Use in a module handler:**
```php
'routes' => [
    'POST /' => function ($req, $res, $args, $view, $db, $container) {
        $requestId = $req->getAttribute('request_id');

        $container->get(\Psr\Log\LoggerInterface::class)->info('order.created', [
            'request_id' => $requestId,
            'order_id'   => 42,
        ]);

        return $res->withHeader('Location', '/orders')->withStatus(302);
    },
],
```

Log output:
```
[2026-07-04 15:00:01] [level=info] [channel=app] order.created request_id=a3f1c8e27b4d9012 order_id=42
```

---

## Example 2 — Real IP middleware

Behind a reverse proxy or Cloudflare, `REMOTE_ADDR` is the proxy's IP, not the visitor's. This middleware resolves the real client IP using [`rafalmasiarek/real-ip-resolver`](https://github.com/rafalmasiarek/php-realIpResolver) and stores it as a request attribute.

**Install:**
```bash
composer require rafalmasiarek/real-ip-resolver
```

**Middleware:**
```php
<?php
// src/Middleware/RealIpMiddleware.php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rafalmasiarek\RealIpResolver\RealIpResolver;

class RealIpMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RealIpResolver $resolver) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $realIp  = $this->resolver->getIp();
        $request = $request->withAttribute('real_ip', $realIp);

        return $handler->handle($request);
    }
}
```

**Register — choose the trusted proxy list that matches your infrastructure:**
```php
use rafalmasiarek\RealIpResolver\RealIpResolver;
use rafalmasiarek\RealIpResolver\TrustedProxy;
use rafalmasiarek\RealIpResolver\IPLists\Cloudflare;
use rafalmasiarek\RealIpResolver\IPLists\Localhost;

// Behind Cloudflare
$resolver = new RealIpResolver(
    new TrustedProxy(array_merge(Localhost::get(), Cloudflare::get()))
);

// Behind an internal reverse proxy only
$resolver = new RealIpResolver(
    new TrustedProxy(array_merge(Localhost::get(), ['10.0.0.0/8', '172.16.0.0/12']))
);

$dashboard->getApp()->add(new \App\Middleware\RealIpMiddleware($resolver));
```

**Use in a module — or combine with the audit log:**
```php
'routes' => [
    'POST /login-action' => function ($req, $res, $args, $view, $db, $container) {
        $ip     = $req->getAttribute('real_ip', $req->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $logger = $container->get(\Psr\Log\LoggerInterface::class);

        $logger->warning('login.failed', ['ip' => $ip, 'email' => 'user@example.com']);

        return $res->withHeader('Location', '/login')->withStatus(302);
    },
],
```

> **Note:** Dashboard Kit's built-in audit log reads IP from `REMOTE_ADDR`. If you add `RealIpMiddleware`, the built-in audit entries still show the proxy IP. To pass the resolved IP through, override `AuthController` or create a custom hook that reads `real_ip` from the request attribute instead.

---

## Example 3 — Security headers (closure)

Simple cases do not need a full class. A closure works equally well.

```php
$dashboard->getApp()->add(function ($req, $handler) {
    return $handler->handle($req)
        ->withHeader('X-Frame-Options',        'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options',  'nosniff')
        ->withHeader('Referrer-Policy',         'strict-origin-when-cross-origin')
        ->withHeader('Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'"
        );
});
```

---

## Attaching middleware to specific routes or groups

Use `getApp()->group()` to apply middleware to a subset of routes.

```php
// Only the /api group gets rate limiting
$dashboard->getApp()->group('/api', function ($group) {
    // routes added by Dashboard are already registered —
    // this adds an extra layer on top of them
})->add(new \App\Middleware\RateLimitMiddleware());
```

Or on a single route:
```php
$dashboard->getApp()->get('/webhook', \App\Handlers\WebhookHandler::class)
    ->add(new \App\Middleware\VerifyHmacMiddleware());
```

---

## Accessing the DI container from middleware

Resolve services the same way as in module handlers.

```php
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->container->get(LoggerInterface::class)->debug('request.start', [
            'path'   => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
        ]);

        return $handler->handle($request);
    }
}
```

```php
// public/index.php
$container = $dashboard->getContainer();
$dashboard->getApp()->add(new \App\Middleware\MyMiddleware($container));
```

---

## Autoloading your middleware

Add your `src/` directory to `composer.json` so Composer finds the classes:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

Then run:
```bash
composer dump-autoload
```

---

## Full index.php example

```php
<?php
// public/index.php

require __DIR__ . '/../vendor/autoload.php';

use App\Middleware\RequestIdMiddleware;
use App\Middleware\RealIpMiddleware;
use Psr\Log\LogLevel;
use RafalMasiarek\DashboardKit\Dashboard;
use rafalmasiarek\RealIpResolver\{RealIpResolver, TrustedProxy};
use rafalmasiarek\RealIpResolver\IPLists\{Cloudflare, Localhost};

$dashboard = Dashboard::create(__DIR__ . '/../', [
    'app_name' => 'My App',
])
    ->setLogLevel(LogLevel::INFO)
    ->on('login', fn($user) => null);

$app = $dashboard->getApp();

// Security headers — outermost layer, runs first and last
$app->add(function ($req, $handler) {
    return $handler->handle($req)
        ->withHeader('X-Frame-Options',       'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy',        'strict-origin-when-cross-origin');
});

// Real IP — resolve before Request ID so the ID can be logged with real IP
$app->add(new RealIpMiddleware(
    new RealIpResolver(new TrustedProxy(array_merge(Localhost::get(), Cloudflare::get())))
));

// Request ID — innermost, request_id attribute available in all handlers
$app->add(new RequestIdMiddleware());

$dashboard->run();
```
