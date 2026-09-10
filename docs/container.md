# Container

Dashboard Kit uses [PHP-DI](https://php-di.org/) as its DI container. The container is built inside `Dashboard::create()` and is accessible via `$dashboard->getContainer()`.

## Accessing the container

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);
$container = $dashboard->getContainer();

// Resolve any registered service
$pdo   = $container->get(PDO::class);
$auth  = $container->get(\AuthKit\Auth::class);
$flash = $container->get(\RafalMasiarek\DashboardKit\Flash::class);
```

## Overriding a binding

Call `set()` on the container before `run()`. Any binding can be replaced, including built-in ones.

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);

$dashboard->getContainer()->set(\Psr\Log\LoggerInterface::class, function () {
    return new \Monolog\Logger('myapp', [$myHandler]);
});

$dashboard->run();
```

---

## Built-in services

### `PDO`

Pre-configured PDO connection to MySQL. Connection parameters are read from environment variables.

| Env var | Default | Description |
|---------|---------|-------------|
| `DB_HOST` | `localhost` | Database host |
| `DB_NAME` | `app` | Database name |
| `DB_USER` | `root` | Username |
| `DB_PASS` | `root` | Password |

```php
$pdo = $container->get(PDO::class);
$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
```

PDO is configured with `ERRMODE_EXCEPTION`. The connection uses `utf8mb4` charset.

---

### `AuthKit\Auth`

The AuthKit authentication service. Handles login, registration, logout, and session management. Schema migrations (users table, role, suspended_at, custom profile fields) are run automatically on first resolve.

```php
$auth = $container->get(\AuthKit\Auth::class);
$user = $auth->getUser();      // currently logged-in user, or null
$auth->logout();
```

See the [AuthKit documentation](https://github.com/rafalmasiarek/authkit) for the full API.

---

### `RafalMasiarek\DashboardKit\Flash`

Flash message bag backed by `$_SESSION`. Messages persist across one redirect.

```php
$flash = $container->get(\RafalMasiarek\DashboardKit\Flash::class);

$flash->add('success', 'Record saved.');
$flash->add('danger',  'Something went wrong.');
$flash->add('info',    'Your request is being processed.');
$flash->add('warning', 'Low disk space detected.');
```

Messages are automatically injected as the `flash` Twig global — no need to pass them to templates manually.

---

### `rafalmasiarek\Csrf\Csrf`

CSRF token service. Requires the `APP_KEY` environment variable (32-byte hex string).

```bash
# Generate a key:
php -r "echo bin2hex(random_bytes(16));"
```

Used internally by `CsrfMiddleware`. In Twig, use `{{ csrf_field('my-form') }}` to render hidden fields. You do not typically resolve this directly.

---

### `RafalMasiarek\DashboardKit\Hook\HookRegistry`

The hook event bus. Prefer `$dashboard->on(event, callable)` over resolving this directly — they are equivalent, but `on()` is the documented API.

```php
$container->get(\RafalMasiarek\DashboardKit\Hook\HookRegistry::class)
    ->on('login', fn($user) => ...);
```

See [hooks.md](hooks.md) for the full event reference.

---

### `RafalMasiarek\DashboardKit\Log\AuditLog`

Structured audit logger for security-relevant events. Wraps `logger.audit`. Used by built-in controllers — resolve it directly when you need to write audit entries from your own code (e.g. custom activation flows).

```php
$audit = $container->get(\RafalMasiarek\DashboardKit\Log\AuditLog::class);

// Activation attempt — one call covers both success and failure.
$audit->activationAttempt(
    success: true,
    ip:      '192.168.1.1',
    userId:  7,
    email:   'user@example.com',
);

$audit->activationAttempt(false, $ip); // failed — no user_id/email
```

Built-in methods: `register`, `login`, `logout`, `passwordChanged`, `emailChanged`, `profileUpdated`, `userSuspended`, `userUnsuspended`, `roleChanged`, `userUpdated`, `userDeleted`, `activationAttempt`.

---

### `Slim\Views\Twig` (via key `"view"`)

The Twig rendering engine. Resolve via the string key `'view'` rather than the class name.

```php
$view = $container->get('view');
return $view->render($response, 'my-template.twig', ['key' => 'value']);
```

#### Global Twig variables

| Variable | Value |
|----------|-------|
| `app_name` | Value of the `app_name` config key |
| `auth` | `Auth` instance (call `auth.isLoggedIn()`, `auth.getUser()`) |
| `flash` | `Flash` instance (rendered automatically by `layout.twig`) |
| `user_fields` | Normalised custom profile field definitions |
| `password_strength` | Resolved password-strength config (`enabled`, `min_score`, `rules`) |
| `registration_enabled` | `true` when `registration: true` is set in config |
| `password_reset_enabled` | `true` when `password_reset: true` is set in config |
| `modules` | Navbar-visible module list |
| `admin_modules` | All admin modules (used on `/admin` landing page) |
| `settings_sections` | Plugin-registered settings sections (see [form-slots.md](form-slots.md)) |
| `user_actions` | Plugin-registered per-user admin action buttons |
| `current_path` | Current request URI path (set per-request by middleware) |

#### Twig functions

| Function | Returns | Description |
|----------|---------|-------------|
| `csrf_field(name)` | HTML | Renders hidden `_csrf` + `_csrf_container` inputs for a named form |
| `gravatar_url(email, size)` | string | Gravatar image URL for a given email and pixel size |
| `form_slot(form, slot)` | HTML | Renders plugin-registered HTML for a named slot (see [form-slots.md](form-slots.md)) |

---

### `Psr\Log\LoggerInterface`

Resolves to the `app` channel logger. Use this for general application events.

```php
$logger = $container->get(\Psr\Log\LoggerInterface::class);
$logger->info('order.created', ['id' => 42]);
```

### Named logger channels

| Key | Channel | Default level | Purpose |
|-----|---------|---------------|---------|
| `logger.app` | `app` | `info` | General events — same as `LoggerInterface` |
| `logger.audit` | `audit` | `info` | Security/action trail |
| `logger.error` | `error` | `error` | 5xx errors only |

```php
$container->get('logger.audit')->info('payment.refunded', ['order_id' => 42]);
```

See [logging.md](logging.md) for configuration and external destinations.

---

### `RafalMasiarek\DashboardKit\Module\ModuleRegistry` (two instances)

| Key | Holds |
|-----|-------|
| `ModuleRegistry::class` | User modules (`modules/` directory) |
| `"admin_module_registry"` | Admin modules (`admin_modules/` directory) |

You rarely need to resolve these directly. See [modules.md](modules.md) and [admin-modules.md](admin-modules.md).

---

## Extension points

### `auth.before_login` / `auth.before_register`

Callables that run before credentials are checked (`before_login`) or before a new account is created (`before_register`). Return `null` to allow the action, or a string error message to block it.

Set via config (simplest):
```php
Dashboard::create(__DIR__ . '/../', [
    'before_login' => function (\Psr\Http\Message\ServerRequestInterface $request): ?string {
        // null = pass, string = block with that error
        return null;
    },
    'before_register' => function (\Psr\Http\Message\ServerRequestInterface $request): ?string {
        return null;
    },
]);
```

Or override the container binding after `create()` — useful from addon code that needs to chain onto an existing callable:
```php
$existing = $container->get('auth.before_login');
$container->set('auth.before_login', fn() => function ($request) use ($existing) {
    if ($existing !== null && ($err = $existing($request)) !== null) {
        return $err;
    }
    // ... your check
    return null;
});
```

> **PHP-DI note:** When storing a callable via `$container->set()`, PHP-DI treats plain closures as factory definitions. Wrap the hook callable in an outer factory: `fn() => function($request) {...}`.

See [hooks.md](hooks.md) for the full hooks reference.

### `SettingsSectionRegistry`

Plugins register sections shown on `/settings` as "Manage" links.

```php
$container->get(\rafalmasiarek\DashboardKit\Extension\SettingsSectionRegistry::class)
    ->register('my-plugin', [
        'title' => 'My Plugin',
        'path'  => '/settings/my-plugin',
        'order' => 10,
    ]);
```

### `UserActionRegistry`

Plugins register per-user action buttons in the Admin → Users list.

```php
$container->get(\rafalmasiarek\DashboardKit\Extension\UserActionRegistry::class)
    ->register('my-action', [
        'label'        => 'Manage',
        'path_pattern' => '/admin/my-plugin/users/{id}',
        'order'        => 10,
    ]);
```

### `FormSlotRegistry`

Plugins inject HTML (or runtime-evaluated callables) into login and register forms without modifying templates.

```php
$container->get(\rafalmasiarek\DashboardKit\Extension\FormSlotRegistry::class)
    ->register('login', 'form_fields', '<div class="g-recaptcha" ...></div>');
```

See [form-slots.md](form-slots.md) for the full reference.

### `user_fields`

Read-only at runtime — set via `Dashboard::create()` config. Contains the normalised field definitions.

```php
$fields = $container->get('user_fields');
// ['first_name' => ['label' => 'First Name', 'type' => 'text', 'max' => 100, 'required' => true], ...]
```

---

## Registering custom services

Any service can be added to the container and resolved from module handlers or hooks:

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);

$dashboard->getContainer()->set('mailer', fn() => new MyMailer($_ENV['SMTP_HOST']));

$dashboard->on('register', function (\AuthKit\User $user) use ($container) {
    $container->get('mailer')->send($user->getEmail(), 'Welcome!', '...');
});

$dashboard->run();
```

From a module handler:

```php
'routes' => [
    'POST /' => function ($req, $res, $args, $view, $db, $container): Response {
        $container->get('mailer')->send(...);
        return $res->withHeader('Location', '/orders')->withStatus(302);
    },
],
```
