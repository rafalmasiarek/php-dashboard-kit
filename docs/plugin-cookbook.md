# Plugin cookbook

A plugin is a PHP package that extends dashboard-kit without modifying core files. It registers routes, Twig templates, hooks, form slots, settings sections, or any combination of these through the DI container and extension registries.

## Anatomy of a plugin

Every plugin follows the same pattern: a single static entry point that accepts the Slim app and the DI container.

```
my-plugin/
├── composer.json
└── src/
    └── MyAddon.php
```

```php
// src/MyAddon.php
namespace Vendor\MyPlugin;

use Psr\Container\ContainerInterface;
use Slim\App;

final class MyAddon
{
    public static function register(App $app, ContainerInterface $container, array $config = []): void
    {
        // wire everything here
    }
}
```

Call it after `Dashboard::create()` and before `$dashboard->run()`:

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);

MyAddon::register($dashboard->getApp(), $dashboard->getContainer());

$dashboard->run();
```

---

## Reading config

### Inline config

```php
MyAddon::register($dashboard->getApp(), $dashboard->getContainer(), [
    'api_key' => 'abc123',
]);
```

### From `Dashboard::create()` (recommended)

Merge from `app.config` so config lives alongside the rest of the application config:

```php
// Dashboard::create() config
'my_plugin' => [
    'api_key' => $_ENV['MY_PLUGIN_KEY'],
],
```

```php
// MyAddon.php
public static function register(App $app, ContainerInterface $container, array $config = []): void
{
    $appConfig = $container->has('app.config') ? (array) $container->get('app.config') : [];
    $config    = \array_merge((array) ($appConfig['my_plugin'] ?? []), $config);

    $apiKey = (string) ($config['api_key'] ?? '');
    // ...
}
```

The `$config` argument takes precedence when both are set — useful for testing or per-call overrides.

---

## Adding routes

```php
public static function register(App $app, ContainerInterface $container, array $config = []): void
{
    $app->get('/my-plugin/dashboard', function ($request, $response) use ($container) {
        $view = $container->get('view');
        return $view->render($response, '@my-plugin/dashboard.twig');
    });

    $app->post('/my-plugin/action', function ($request, $response) use ($container) {
        // ...
        return $response->withHeader('Location', '/my-plugin/dashboard')->withStatus(302);
    });
}
```

Routes added by plugins are protected by `AuthMiddleware` only if they are inside the auth group. To add a route that requires login, use the group that dashboard-kit has already registered, or wrap your route in middleware explicitly:

```php
use rafalmasiarek\DashboardKit\Middleware\AuthMiddleware;

$app->get('/my-plugin/private', function ($req, $res) use ($container) {
    // ...
})->add($container->get(AuthMiddleware::class));
```

---

## Adding Twig templates

Get the already-built Twig loader and register a namespace:

```php
$view   = $container->get('view');
$loader = $view->getEnvironment()->getLoader();
$loader->addPath(__DIR__ . '/../templates', 'my-plugin');
```

Templates in `src/../templates/` are then available as `@my-plugin/dashboard.twig`.

```twig
{# templates/dashboard.twig #}
{% extends 'layout.twig' %}

{% block title %}My Plugin{% endblock %}

{% block content %}
<div class="container-fluid py-4">
    <h1>My Plugin</h1>
</div>
{% endblock %}
```

---

## Hooking into events

Use `HookRegistry` to listen for dashboard events. Prefer `$dashboard->on()` from the entry point, or resolve `HookRegistry` inside the plugin:

```php
use rafalmasiarek\DashboardKit\Hook\HookRegistry;

$hooks = $container->get(HookRegistry::class);

$hooks->on('login', function (\AuthKit\User $user) use ($container) {
    $container->get('logger.audit')->info('plugin.login_recorded', [
        'user_id' => $user->getId(),
    ]);
});

$hooks->on('register', function (\AuthKit\User $user) use ($container) {
    // send welcome email, etc.
});
```

`on()` replaces any previously registered listener for that event. If you need the default flash message plus your own behaviour, add it explicitly:

```php
use rafalmasiarek\DashboardKit\Flash;

$hooks->on('register', function (\AuthKit\User $user) use ($container) {
    $container->get(Flash::class)->add('success', 'Account created. You can now log in.');
    // your additional logic
});
```

See [hooks.md](hooks.md) for the full event reference.

---

## Blocking login or registration

Return `null` to allow, a string to block with that message:

```php
$existing = $container->get('auth.before_login');

$container->set('auth.before_login', static fn() => static function ($request) use ($existing): ?string {
    if ($existing !== null && ($err = $existing($request)) !== null) {
        return $err;
    }

    $body = (array) $request->getParsedBody();
    if (empty($body['agreed_to_terms'])) {
        return 'You must accept the terms of service.';
    }

    return null;
});
```

The outer `static fn()` wrapper is required — PHP-DI treats plain closures passed to `set()` as factory definitions and tries to auto-wire their parameters from the container. Wrapping prevents that.

The same pattern applies to `auth.before_register`.

---

## Injecting HTML into login / register forms

`FormSlotRegistry` lets you inject HTML into named slots without modifying core templates:

```php
use rafalmasiarek\DashboardKit\Extension\FormSlotRegistry;

$slots = $container->get(FormSlotRegistry::class);

// Static string
$slots->register('login', 'form_fields', '<div class="alert alert-info">Maintenance window tonight.</div>');

// Callable — evaluated at render time (can read $_SESSION, etc.)
$slots->register('login', 'form_fields', static function (): string {
    return ($_SESSION['show_banner'] ?? false)
        ? '<div class="alert alert-warning">Your session will expire soon.</div>'
        : '';
});

// order parameter controls rendering sequence (default: 100)
$slots->register('login', 'form_fields', $widgetA, order: 10);
$slots->register('login', 'form_fields', $widgetB, order: 50);
```

Built-in slots:

| Form | Slot | Position |
|------|------|----------|
| `login` | `form_fields` | Inside `<form>`, before the submit button |
| `login` | `scripts` | In `{% block scripts %}`, outside the form |
| `register` | `form_fields` | Inside `<form>`, before the submit button |
| `register` | `scripts` | At the end of `{% block scripts %}` |

See [form-slots.md](form-slots.md) for the full reference.

---

## Adding a settings section

Register a card on the `/settings` page:

```php
use rafalmasiarek\DashboardKit\Extension\SettingsSectionRegistry;

$container->get(SettingsSectionRegistry::class)->register('my-plugin', [
    'title' => 'My Plugin',
    'path'  => '/my-plugin/settings',
    'order' => 10,
]);
```

---

## Adding a per-user action button in Admin → Users

```php
use rafalmasiarek\DashboardKit\Extension\UserActionRegistry;

$container->get(UserActionRegistry::class)->register('my-plugin', [
    'label'        => 'Manage',
    'path_pattern' => '/my-plugin/users/{id}',
    'order'        => 10,
]);
```

`{id}` in `path_pattern` is replaced with the user ID when the button is rendered.

---

## Registering services in the container

Register any service before `run()`. It becomes available inside module handlers and hooks via `$container->get()`:

```php
$container->set('my_plugin.client', static fn() => new MyApiClient($apiKey));
```

From a module handler:

```php
'routes' => [
    'GET /' => function ($req, $res, $args, $view, $db, $container) {
        $result = $container->get('my_plugin.client')->fetchData();
        return $view->render($res, '@my-plugin/index.twig', ['data' => $result]);
    },
],
```

---

## Typed extension points

Some plugins define an interface that applications implement and bind in the container. The plugin resolves it at runtime. This pattern avoids hard-coding implementation choices in the plugin.

```php
// Plugin side — defines the interface and a safe no-op default
interface NotifierInterface
{
    public function send(string $event, array $payload): void;
}

final class NoopNotifier implements NotifierInterface
{
    public function send(string $event, array $payload): void {}
}
```

```php
// Plugin side — resolves from container, falls back to noop
$notifier = $container->has(NotifierInterface::class)
    ? $container->get(NotifierInterface::class)
    : new NoopNotifier();
```

```php
// Application side — binds a real implementation
$container->set(NotifierInterface::class, static fn() => new SlackNotifier($_ENV['SLACK_WEBHOOK']));
```

This is how `dashboard-kit-scheduler` handles monitoring: the plugin ships `MonitoringInterface` + `NoopMonitoring`, the application binds a real implementation (`HealthChecksMonitoring`) when needed.

---

## Worked example — activity log plugin

A minimal plugin that logs every login to a custom table and adds a settings section.

```
my-activity-log/
├── composer.json
└── src/
    └── ActivityLogAddon.php
```

```php
namespace Vendor\ActivityLog;

use Psr\Container\ContainerInterface;
use rafalmasiarek\DashboardKit\Extension\SettingsSectionRegistry;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use Slim\App;

final class ActivityLogAddon
{
    public static function register(App $app, ContainerInterface $container, array $config = []): void
    {
        $appConfig = $container->has('app.config') ? (array) $container->get('app.config') : [];
        $config    = \array_merge((array) ($appConfig['activity_log'] ?? []), $config);

        $retention = (int) ($config['retention_days'] ?? 30);

        // Ensure table exists
        $pdo = $container->get(\PDO::class);
        $pdo->exec('CREATE TABLE IF NOT EXISTS activity_log (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            event      VARCHAR(64) NOT NULL,
            user_id    INT,
            ip         VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        // Listen for login events
        $hooks = $container->get(HookRegistry::class);

        $hooks->on('login', function (\AuthKit\User $user) use ($pdo) {
            $pdo->prepare('INSERT INTO activity_log (event, user_id) VALUES (?, ?)')
                ->execute(['login', $user->getId()]);
        });

        // Add a settings section
        $container->get(SettingsSectionRegistry::class)->register('activity-log', [
            'title' => 'Activity Log',
            'path'  => '/activity-log',
            'order' => 20,
        ]);

        // Register Twig namespace
        $container->get('view')->getEnvironment()->getLoader()
            ->addPath(__DIR__ . '/../templates', 'activity-log');

        // Register route
        $app->get('/activity-log', function ($request, $response) use ($container, $pdo, $retention) {
            $rows = $pdo->query(
                "SELECT * FROM activity_log
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$retention} DAY)
                 ORDER BY created_at DESC LIMIT 200"
            )->fetchAll();

            return $container->get('view')->render($response, '@activity-log/index.twig', [
                'events' => $rows,
            ]);
        });
    }
}
```

Usage:

```php
// Dashboard::create() config
'activity_log' => [
    'retention_days' => 90,
],

// index.php
ActivityLogAddon::register($dashboard->getApp(), $dashboard->getContainer());
```
