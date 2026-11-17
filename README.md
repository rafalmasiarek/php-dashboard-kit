# dashboard-kit
[![Packagist Version](https://img.shields.io/packagist/v/rafalmasiarek/dashboard-kit)](https://packagist.org/packages/rafalmasiarek/dashboard-kit)
[![Total Downloads](https://img.shields.io/packagist/dt/rafalmasiarek/dashboard-kit)](https://packagist.org/packages/rafalmasiarek/dashboard-kit)
[![PHP](https://img.shields.io/packagist/php-v/rafalmasiarek/dashboard-kit)](https://packagist.org/packages/rafalmasiarek/dashboard-kit)
[![License](https://img.shields.io/packagist/l/rafalmasiarek/dashboard-kit)](https://packagist.org/packages/rafalmasiarek/dashboard-kit)

Slim 4 dashboard framework. Provides module auto-discovery, dynamic navbar, session-based auth (via AuthKit), CSRF protection, Twig templating, structured logging, mail, and a plugin extension system.



## License
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

```text
The MIT License (MIT)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files, to deal in the Software
without restriction.

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.

Source: https://opensource.org/licenses/MIT
```
See [LICENSE](LICENSE) for full details.
## Authors

- Rafał Masiarek | [website](https://masiarek.pl) | [github](https://github.com/rafalmasiarek)


## Requirements

- PHP 8.2+
- MySQL 8 or SQLite
- Composer

## Installation

```bash
composer require rafalmasiarek/dashboard-kit
```

## Quick start

```php
use DI\ContainerBuilder;
use rafalmasiarek\DashboardKit\Dashboard;
use Slim\Factory\AppFactory;

$container = (new ContainerBuilder())->build();
AppFactory::setContainer($container);
$app = AppFactory::create();

Dashboard::create($app, $container, [
    'db' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'dbname'   => 'myapp',
        'user'     => 'root',
        'password' => 'secret',
    ],
    'modules_path'       => __DIR__ . '/modules',
    'admin_modules_path' => __DIR__ . '/admin_modules',
    'storage_path'       => __DIR__ . '/storage',
    'templates_path'     => __DIR__ . '/templates',
    'app' => [
        'env'  => 'production',
        'name' => 'My App',
    ],
]);

$app->run();
```

## Module structure

Each module is a directory containing `module.php`:

```php
// modules/notes/module.php
return [
    'title'  => 'Notes',
    'icon'   => '📝',
    'render' => function ($req, $res, $args, $twig, $db, $container) {
        return $twig->render($res, 'notes/index.twig', []);
    },
];
```

Modules appear in the navbar automatically. Admin modules (under `admin_modules_path`) appear in the `/admin` panel.


## Database schema

Declare tables directly in `module.php` under the `schema` key. On every boot the framework compares a hash of each table's definition against the stored hash — unchanged tables are skipped entirely. Changed or new tables are created (`CREATE TABLE IF NOT EXISTS`) and any new columns are added (`ALTER TABLE ADD COLUMN`). Columns are never dropped.

```php
// modules/notes/module.php
return [
    'title'  => 'Notes',
    'schema' => [
        'notes' => [
            'columns' => [
                'title'   => ['type' => 'string', 'null' => false],
                'body'    => ['type' => 'text'],
                'user_id' => ['type' => 'uuid', 'null' => false],
                'active'  => ['type' => 'tinyint', 'default' => 1],
            ],
            'indexes' => [
                ['columns' => ['user_id']],
            ],
        ],
    ],
    'render' => function ($req, $res, $args, $twig, $db, $container) { ... },
];
```

Every managed table automatically gets `id` (UUID `CHAR(36)`) and `created_at` (DATETIME, DEFAULT CURRENT_TIMESTAMP). Supported column types: `string`, `text`, `int`/`integer`, `bigint`, `tinyint`, `bool`/`boolean`, `decimal`, `float`, `datetime`, `date`, `json`, `uuid`.

### Tables outside modules

Tables that do not belong to any module can be declared at the top level of the config under `schema`:

```php
Dashboard::create($app, $container, [
    // ...
    'schema' => [
        'shared_settings' => [
            'columns' => [
                'key'   => ['type' => 'string', 'null' => false, 'unique' => true],
                'value' => ['type' => 'text'],
            ],
        ],
    ],
]);
```

### Table prefix

Add `table_prefix` to the config to namespace all managed tables. System tables (`_schema_state`, `_table_version`, `_query_cache`) are never prefixed.

```php
Dashboard::create($app, $container, [
    'table_prefix' => 'app_',
    // module table "notes" → "app_notes"
]);
```

In handlers that build raw SQL, use `CachingPdo::table()` to apply the prefix consistently:

```php
$stmt = $db->prepare("SELECT * FROM `" . $db->table('notes') . "` WHERE id = ?");
```

The prefix is also available as `db.table_prefix` in the container.

### Seed data

Declare default rows under the `seed` key (top-level config or per-module). Each seed table is paired with a `match` column for the existence check — rows are inserted only when no matching record exists, making seeds idempotent on every boot.

```php
'seed' => [
    'users' => [
        'match'   => 'email',        // column used for the existence check before inserting
        'with_id' => true,           // auto-generate UUID for id if absent (default)
        'rows'    => [
            [
                'email'         => 'admin@example.com',
                'role'          => 'admin',
                'active'        => 1,
                'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            ],
        ],
    ],
    'api_scopes' => [
        'match'   => 'name',
        'with_id' => false,          // table uses AUTO_INCREMENT — skip UUID injection
        'rows'    => [
            ['name' => 'notes:read',  'description' => 'Read notes'],
            ['name' => 'notes:write', 'description' => 'Manage notes'],
        ],
    ],
],
```

`created_at` is never injected — the database fills it in via `DEFAULT CURRENT_TIMESTAMP`.

## Query cache

All SELECT queries issued through `PDO::class` from the container are transparently cached — no code changes required in handlers. The cache is version-keyed: any write to a table automatically makes all cached SELECT results for that table unreachable without explicit invalidation.

Cache behaviour by statement type:

| Type | Behaviour |
|---|---|
| SELECT | Checks cache before executing; stores `fetchAll()` result on miss |
| INSERT / UPDATE / DELETE / REPLACE | Executes normally; bumps version counter for affected tables |
| System tables (`_query_cache`, `_table_version`, `_schema_state`) | Always bypass cache |

Only `fetchAll()` results are cached. `fetch()` and `fetchColumn()` always hit the database.

Default TTL is 300 seconds. The cache is stored in the `_query_cache` table and never goes stale: a write to table `T` makes all cached SELECT keys that referenced `T` unreachable — they can never be served after the write.


## Plugin extension points

Plugins can extend the UI without modifying core templates:

### Settings sections

Register a section card on the `/settings` page:

```php
$container->get(SettingsSectionRegistry::class)->register('my-plugin', [
    'title' => 'My Plugin',
    'path'  => '/settings/my-plugin',
    'order' => 10,
]);
```

### User action buttons

Register a button in Admin → Users per-user row:

```php
$container->get(UserActionRegistry::class)->register('my-action', [
    'label'        => 'Manage',
    'path_pattern' => '/admin/my-plugin/users/{id}',
    'order'        => 10,
]);
```

### Form slots

Inject HTML into the login or register forms without modifying core templates. Resolve `FormSlotRegistry` from the container and call `register()` before `run()`.

Built-in slots:

| Form | Slot | Position |
|------|------|----------|
| `login` | `form_fields` | Inside `<form>`, before the submit button |
| `login` | `scripts` | In `{% block scripts %}`, outside the form |
| `register` | `form_fields` | Inside `<form>`, before the submit button |
| `register` | `scripts` | At the end of `{% block scripts %}` |

```php
use rafalmasiarek\DashboardKit\Extension\FormSlotRegistry;

$registry = $dashboard->getContainer()->get(FormSlotRegistry::class);

// Static HTML string
$registry->register('login', 'form_fields', '<div class="g-recaptcha mb-3" data-sitekey="..."></div>');

// Callable — evaluated at render time, can inspect session or other runtime state
$registry->register('login', 'form_fields', static function (): string {
    return ((int) ($_SESSION['login_fails'] ?? 0)) >= 3
        ? '<div class="g-recaptcha mb-3" data-sitekey="..."></div>'
        : '';
});

// Optional order parameter — lower numbers render first (default: 100)
$registry->register('login', 'form_fields', $tosCheckbox, order: 10);
$registry->register('login', 'form_fields', $captchaWidget, order: 50);
```

In Twig, slots are rendered via `{{ form_slot('login', 'form_fields') }}` — already present in the core templates, no template changes needed.

See [`docs/form-slots.md`](docs/form-slots.md) for the full reference including the PHP-DI callable pitfall.

### Auth failure hooks

Emitted after failed credential checks, useful for rate limiting and abuse detection:

```php
$dashboard->on('login_failed', function (string $email, string $ip) {
    // track failure, increment counter, notify, etc.
});

$dashboard->on('register_failed', function (string $email, string $errorMessage, string $ip) {
    // track registration abuse, duplicate detection, etc.
});
```


## Error handling

`SafeErrorHandler` handles all Slim errors with content negotiation:

- JSON clients (`Accept: application/json`, AJAX, CLI) receive:
  ```json
  { "status": "error", "code": 404, "message": "Not found.", "data": {}, "errors": [] }
  ```
- Browsers receive a Twig error template (`errors/{code}.twig`, `errors/5xx.twig`, `errors/error.twig`)
- Secrets (tokens, passwords, API keys) are redacted from all error output

## Logging

Named Monolog channels in key=value format, registered as `logger.{name}` in the container:

```php
// config
'logging' => [
    'channels' => [
        'app'   => ['path' => storage_path('logs/app.log'),   'level' => 'info'],
        'audit' => ['path' => storage_path('logs/audit.log'), 'level' => 'info'],
        'error' => ['path' => storage_path('logs/error.log'), 'level' => 'warning'],
    ],
],
```

Audit events use dot-notation names: `dashboard.login`, `dashboard.register`, `dashboard.role_changed`, etc.

## Redirect after login

When `AuthMiddleware` blocks an unauthenticated request it redirects to `/login?from=<encoded-path>`. After successful login the controller redirects back to that path instead of the default `/`.

Only same-origin paths are accepted (`from` must start with `/` and not with `//`) to prevent open redirect attacks.

Logged-in users visiting `/login` or `/register` are redirected immediately to `/` — these routes are guest-only.

## Built-in routes

| Route | Description |
|-------|-------------|
| `GET /` | Home page — auth-aware, renders `home.twig` |
| `GET/POST /login` | Login |
| `GET /logout` | Logout |
| `GET/POST /register` | Registration |
| `GET /settings` | User settings |
| `GET /admin` | Admin panel |
| `GET /admin/users` | User management |

## Home page

`GET /` renders the built-in `home.twig`. The template checks `auth.isLoggedIn()` and shows different content for guests and authenticated users.

To customise, place `home.twig` in your application's `templates/` directory — it takes precedence over the built-in:

```twig
{# templates/home.twig #}
{% extends "layout.twig" %}

{% block content %}
{% if auth.isLoggedIn() %}
    <h2>Welcome, {{ auth.getUser().get('email') }}</h2>
{% else %}
    <h1>{{ app_name }}</h1>
    <a href="/login" class="btn btn-primary">Log in</a>
{% endif %}
{% endblock %}
```

Available Twig globals on this page: `auth`, `app_name`, `modules`, `registration_enabled`, `flash`.

## Templating and layout customisation

`layout.twig` is split into named blocks that can be overridden independently:

| Block | Default | Use |
|---|---|---|
| `{% block head %}` | empty | Extra `<link>` / `<meta>` tags |
| `{% block navbar %}` | Bootstrap navbar | Replace or remove the navbar |
| `{% block header %}` | empty | Hero/banner below navbar |
| `{% block content %}` | empty | Main page body |
| `{% block footer %}` | empty | Site-wide footer |
| `{% block scripts %}` | empty | Extra scripts before `</body>` |

To override a single block without replacing the whole layout, extend `@dashboard-kit/layout.twig` (the namespace avoids infinite recursion when your file is also called `layout.twig`):

```twig
{# templates/layout.twig #}
{% extends "@dashboard-kit/layout.twig" %}

{% block footer %}
<footer class="text-center text-muted small py-4">&copy; {{ "now"|date("Y") }} {{ app_name }}</footer>
{% endblock %}
```

See [`docs/templating.md`](docs/templating.md) for the full reference.


## Index

### Classes

- [`CachingPdo`](#cachingpdo) — `rafalmasiarek\DashboardKit\Cache`
- [`CachingStatement`](#cachingstatement) — `rafalmasiarek\DashboardKit\Cache`
- [`ArrayQueryCacheDriver`](#arrayquerycachedriver) — `rafalmasiarek\DashboardKit\Cache\Driver`
- [`NullQueryCacheDriver`](#nullquerycachedriver) — `rafalmasiarek\DashboardKit\Cache\Driver`
- [`PdoQueryCacheDriver`](#pdoquerycachedriver) — `rafalmasiarek\DashboardKit\Cache\Driver`
- [`SqlTableExtractor`](#sqltableextractor) — `rafalmasiarek\DashboardKit\Cache`
- [`TransactionState`](#transactionstate) — `rafalmasiarek\DashboardKit\Cache`
- [`AuthController`](#authcontroller) — `rafalmasiarek\DashboardKit\Controllers`
- [`PasswordResetController`](#passwordresetcontroller) — `rafalmasiarek\DashboardKit\Controllers`
- [`SettingsController`](#settingscontroller) — `rafalmasiarek\DashboardKit\Controllers`
- [`UserController`](#usercontroller) — `rafalmasiarek\DashboardKit\Controllers`
- [`Dashboard`](#dashboard) — `rafalmasiarek\DashboardKit`
- [`ActiveUserExtension`](#activeuserextension) — `rafalmasiarek\DashboardKit\Extension`
- [`FormSlotRegistry`](#formslotregistry) — `rafalmasiarek\DashboardKit\Extension`
- [`SettingsSectionRegistry`](#settingssectionregistry) — `rafalmasiarek\DashboardKit\Extension`
- [`SuspendedUserExtension`](#suspendeduserextension) — `rafalmasiarek\DashboardKit\Extension`
- [`UserActionRegistry`](#useractionregistry) — `rafalmasiarek\DashboardKit\Extension`
- [`Flash`](#flash) — `rafalmasiarek\DashboardKit`
- [`FlashAuthHook`](#flashauthhook) — `rafalmasiarek\DashboardKit\Hook`
- [`HookRegistry`](#hookregistry) — `rafalmasiarek\DashboardKit\Hook`
- [`HttpMessagePicker`](#httpmessagepicker) — `rafalmasiarek\DashboardKit\Http`
- [`RequestTimingMiddleware`](#requesttimingmiddleware) — `rafalmasiarek\DashboardKit\Http`
- [`SafeErrorHandler`](#safeerrorhandler) — `rafalmasiarek\DashboardKit\Http`
- [`AuditLog`](#auditlog) — `rafalmasiarek\DashboardKit\Log`
- [`KvLineFormatter`](#kvlineformatter) — `rafalmasiarek\DashboardKit\Log`
- [`LoggerFactory`](#loggerfactory) — `rafalmasiarek\DashboardKit\Log`
- [`RequestHeadersProcessor`](#requestheadersprocessor) — `rafalmasiarek\DashboardKit\Log`
- [`RequestLogSanitizer`](#requestlogsanitizer) — `rafalmasiarek\DashboardKit\Log`
- [`SecretRedactionProcessor`](#secretredactionprocessor) — `rafalmasiarek\DashboardKit\Log`
- [`StderrLogger`](#stderrlogger) — `rafalmasiarek\DashboardKit\Log`
- [`NullDriver`](#nulldriver) — `rafalmasiarek\DashboardKit\Mail\Driver`
- [`OutboundMail`](#outboundmail) — `rafalmasiarek\DashboardKit\Mail\Driver`
- [`SmtpDriver`](#smtpdriver) — `rafalmasiarek\DashboardKit\Mail\Driver`
- [`MailException`](#mailexception) — `rafalmasiarek\DashboardKit\Mail\Exception`
- [`MailMessage`](#mailmessage) — `rafalmasiarek\DashboardKit\Mail`
- [`Mailer`](#mailer) — `rafalmasiarek\DashboardKit\Mail`
- [`AuthMiddleware`](#authmiddleware) — `rafalmasiarek\DashboardKit\Middleware`
- [`CsrfMiddleware`](#csrfmiddleware) — `rafalmasiarek\DashboardKit\Middleware`
- [`RequestHeadersMiddleware`](#requestheadersmiddleware) — `rafalmasiarek\DashboardKit\Middleware`
- [`RoleMiddleware`](#rolemiddleware) — `rafalmasiarek\DashboardKit\Middleware`
- [`SessionMiddleware`](#sessionmiddleware) — `rafalmasiarek\DashboardKit\Middleware`
- [`Collection`](#collection) — `rafalmasiarek\DashboardKit\Model`
- [`Model`](#model) — `rafalmasiarek\DashboardKit\Model`
- [`QueryBuilder`](#querybuilder) — `rafalmasiarek\DashboardKit\Model`
- [`Raw`](#raw) — `rafalmasiarek\DashboardKit\Model`
- [`ModuleRegistry`](#moduleregistry) — `rafalmasiarek\DashboardKit\Module`
- [`DashboardUserColumnsProvider`](#dashboardusercolumnsprovider) — `rafalmasiarek\DashboardKit\Schema`
- [`ModuleSchemaBuilder`](#moduleschemabuilder) — `rafalmasiarek\DashboardKit\Schema`
- [`PasswordResetSchemaProvider`](#passwordresetschemaprovider) — `rafalmasiarek\DashboardKit\Schema`
- [`SchemaInspector`](#schemainspector) — `rafalmasiarek\DashboardKit\Schema`
- [`SchemaStateManager`](#schemastatemanager) — `rafalmasiarek\DashboardKit\Schema`
- [`SeedRunner`](#seedrunner) — `rafalmasiarek\DashboardKit\Schema`
- [`UserFieldsSchemaProvider`](#userfieldsschemaprovider) — `rafalmasiarek\DashboardKit\Schema`
- [`SecretRegistry`](#secretregistry) — `rafalmasiarek\DashboardKit\Security`
- [`LazyPhpSessionTransport`](#lazyphpsessiontransport) — `rafalmasiarek\DashboardKit\Transport`
- [`CsrfExtension`](#csrfextension) — `rafalmasiarek\DashboardKit\Twig`
- [`DashboardExtension`](#dashboardextension) — `rafalmasiarek\DashboardKit\Twig`
- [`PasswordStrength`](#passwordstrength) — `rafalmasiarek\DashboardKit\Utils`
- [`SystemClock`](#systemclock) — `rafalmasiarek\DashboardKit\Utils`
- [`TimezoneClock`](#timezoneclock) — `rafalmasiarek\DashboardKit\Utils`
- [`Uuid`](#uuid) — `rafalmasiarek\DashboardKit\Utils`
- [`UuidUserIdPolicy`](#uuiduseridpolicy) — `rafalmasiarek\DashboardKit\Utils`

### Interfaces

- [`QueryCacheDriverInterface`](#querycachedriverinterface) — `rafalmasiarek\DashboardKit\Cache`
- [`MailDriverInterface`](#maildriverinterface) — `rafalmasiarek\DashboardKit\Mail\Driver`
- [`MailerInterface`](#mailerinterface) — `rafalmasiarek\DashboardKit\Mail`
- [`ClockInterface`](#clockinterface) — `rafalmasiarek\DashboardKit\Utils`

## `CachingPdo`

*class* — `rafalmasiarek\DashboardKit\Cache\CachingPdo` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L26)

PDO subclass that caches SELECT query results with tag-based invalidation. Drop-in replacement for PDO: type-compatible (extends PDO), registered as PDO::class in the DI container. All prepared statements automatically become CachingStatement instances via PDO::ATTR_STATEMENT_CLASS. exec() is overridden to invalidate tagged cache entries after data-modifying statements that bypass prepare/execute (e.g. raw INSERT/UPDATE/DELETE strings). The backing driver must be constructed against a separate raw PDO connection ('pdo.raw' in the container) to avoid a circular dependency — the driver itself queries _query_cache, which is already excluded from caching in CachingStatement (SYSTEM_TABLES).

extends `PDO`

**Depends on:** [`QueryCacheDriverInterface`](#querycachedriverinterface), [`SqlTableExtractor`](#sqltableextractor), [`TransactionState`](#transactionstate)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$extractor` | [`SqlTableExtractor`](#sqltableextractor) | private | Shared SQL table name parser reused across all exec() calls. |
| `$txState` | [`TransactionState`](#transactionstate) | private | Shared transaction state bag coordinating this connection and all its prepared statements. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#cachingpdo-__construct) | public | — | — |
| [`table()`](#cachingpdo-table) | public | `string` | Returns the prefixed table name for use in raw SQL queries. Use this in every handler that builds SQL manually so that the configured table prefix is applied consistently: $db->prepare("SELECT * FROM `" . $db->table('short_links') . "` WHERE ...") When no prefix is configured, the name is returned as-is. |
| [`exec()`](#cachingpdo-exec) | public | `int\|false` | Executes a raw SQL statement and invalidates cache entries for write statements. DDL statements (CREATE, ALTER, DROP) are executed unchanged; they are not detected as writes and do not trigger invalidation. When a transaction is active, dirty table names are accumulated in TransactionState rather than invalidated immediately; CachingPdo::commit() flushes them once the transaction is durably written. |
| [`beginTransaction()`](#cachingpdo-begintransaction) | public | `bool` | Starts a PDO transaction and enables transaction-mode on the shared state. While the transaction is active, CachingStatement will bypass the query cache on reads and accumulate write table names in TransactionState::$dirtyTables rather than invalidating immediately. |
| [`commit()`](#cachingpdo-commit) | public | `bool` | Commits the active transaction and invalidates cache entries for all tables written during it. Invalidation is deferred until commit so that concurrent readers only see the cache purge after the transaction is durably written, not mid-flight. |
| [`rollBack()`](#cachingpdo-rollback) | public | `bool` | Rolls back the active transaction and discards accumulated dirty-table tracking. No invalidation is issued — rolled-back writes never reached the DB, so cached SELECT results remain valid and no cache purge is needed. |

<details>
<summary><code>__construct()</code></summary>

#### `CachingPdo __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L51)

```php
public function __construct(string $dsn, string $username, string $password, array $options, QueryCacheDriverInterface $driver, int $defaultTtl, string $tablePrefix)
```

| Param | Type | Description |
|---|---|---|
| `$dsn` | `string` | PDO DSN string. |
| `$username` | `string` | Database user. |
| `$password` | `string` | Database password. |
| `$options` | `array<int, mixed>` | PDO driver options. |
| `$driver` | [`QueryCacheDriverInterface`](#querycachedriverinterface) | Tag-based cache driver (must use raw PDO internally). |
| `$defaultTtl` | `int` | Default cache TTL in seconds. Default: 300. |
| `$tablePrefix` | `string` | Optional prefix prepended by table(). Default: ''. |

</details>

<details>
<summary><code>table()</code></summary>

#### `CachingPdo table()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L83)

```php
public function table(string $name): string
```

Returns the prefixed table name for use in raw SQL queries. Use this in every handler that builds SQL manually so that the configured table prefix is applied consistently: $db->prepare("SELECT * FROM `" . $db->table('short_links') . "` WHERE ...") When no prefix is configured, the name is returned as-is.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Logical (un-prefixed) table name. |

**Returns:** `string` — Prefixed table name ready for use in SQL.

</details>

<details>
<summary><code>exec()</code></summary>

#### `CachingPdo exec()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L101)

```php
public function exec(string $statement): int|false
```

Executes a raw SQL statement and invalidates cache entries for write statements. DDL statements (CREATE, ALTER, DROP) are executed unchanged; they are not detected as writes and do not trigger invalidation. When a transaction is active, dirty table names are accumulated in TransactionState rather than invalidated immediately; CachingPdo::commit() flushes them once the transaction is durably written.

| Param | Type | Description |
|---|---|---|
| `$statement` | `string` | SQL to execute. |

**Returns:** `int\|false` — Number of affected rows, or false on failure.

</details>

<details>
<summary><code>beginTransaction()</code></summary>

#### `CachingPdo beginTransaction()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L128)

```php
public function beginTransaction(): bool
```

Starts a PDO transaction and enables transaction-mode on the shared state. While the transaction is active, CachingStatement will bypass the query cache on reads and accumulate write table names in TransactionState::$dirtyTables rather than invalidating immediately.

**Returns:** `bool`

</details>

<details>
<summary><code>commit()</code></summary>

#### `CachingPdo commit()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L146)

```php
public function commit(): bool
```

Commits the active transaction and invalidates cache entries for all tables written during it. Invalidation is deferred until commit so that concurrent readers only see the cache purge after the transaction is durably written, not mid-flight.

**Returns:** `bool`

</details>

<details>
<summary><code>rollBack()</code></summary>

#### `CachingPdo rollBack()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingPdo.php#L166)

```php
public function rollBack(): bool
```

Rolls back the active transaction and discards accumulated dirty-table tracking. No invalidation is issued — rolled-back writes never reached the DB, so cached SELECT results remain valid and no cache purge is needed.

**Returns:** `bool`

</details>

## `CachingStatement`

*final class* — `rafalmasiarek\DashboardKit\Cache\CachingStatement` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L32)

PDOStatement subclass that transparently caches SELECT results using tag-based invalidation. Instantiated automatically by CachingPdo via PDO::ATTR_STATEMENT_CLASS. Never constructed directly. Behaviour per statement type: SELECT  — checks the driver before executing; on miss, executes and caches the fetchAll() result keyed by sql + params (no version embedding). WRITE   — executes normally, then invalidates all cache entries tagged with the affected table names. SYSTEM  — queries touching _query_cache or _schema_state are always passed through to PDO unchanged (no caching, no invalidation). TRANSACTION-ACTIVE — bypasses cache entirely; write table names are appended to TransactionState::$dirtyTables for deferred invalidation on commit. Only fetchAll() results are cached. fetch() and fetchColumn() always hit the DB. The cache key is md5(sql + params) — no version numbers embedded. Invalidation is tag-driven: a write to table T deletes every entry tagged with T.

extends `PDOStatement`

**Depends on:** [`QueryCacheDriverInterface`](#querycachedriverinterface), [`SqlTableExtractor`](#sqltableextractor), [`TransactionState`](#transactionstate)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$boundValues` | `array<string\|int, mixed>` | private | Values captured by bindValue() calls, used to build the cache key when execute() is called without an explicit $params array. |
| `$hitResult` | `list<mixed>\|null` | private | Populated on a cache hit; consumed and cleared by fetchAll(). |
| `$pendingKey` | `string\|null` | private | Cache key waiting to be written after a SELECT miss. Set in execute(), consumed in fetchAll(). |
| `$pendingTags` | `list<string>\|null` | private | Table tags associated with the pending cache write. Set alongside $pendingKey in execute(), consumed in fetchAll(). |
| `$fromCache` | `bool` | private | True when the last execute() was served from cache. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#cachingstatement-__construct) | protected | — | — |
| [`bindValue()`](#cachingstatement-bindvalue) | public | `bool` | Intercepts parameter binding to track values for cache-key construction. |
| [`execute()`](#cachingstatement-execute) | public | `bool` | Executes the statement with transparent cache read-through for SELECTs. |
| [`fetchAll()`](#cachingstatement-fetchall) | public | `list<mixed>` | Returns cached rows on a hit, or fetches and stores them on a miss. |
| [`buildKey()`](#cachingstatement-buildkey) | private | `string` | Builds a deterministic cache key from the SQL and its bound parameters. No version numbers are embedded — invalidation is handled by tag deletion rather than key rotation, so the key remains stable across writes. |
| [`reset()`](#cachingstatement-reset) | private | `void` | Resets all per-execution state before each execute() call. |

<details>
<summary><code>__construct()</code></summary>

#### `CachingStatement __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L85)

```php
protected function __construct(QueryCacheDriverInterface $driver, int $defaultTtl, SqlTableExtractor $extractor, TransactionState $txState)
```

| Param | Type | Description |
|---|---|---|
| `$driver` | [`QueryCacheDriverInterface`](#querycachedriverinterface) | Tag-based cache driver. |
| `$defaultTtl` | `int` | Cache entry TTL in seconds. |
| `$extractor` | [`SqlTableExtractor`](#sqltableextractor) | SQL table-name parser. |
| `$txState` | [`TransactionState`](#transactionstate) | Shared transaction coordination bag from CachingPdo. |

</details>

<details>
<summary><code>bindValue()</code></summary>

#### `CachingStatement bindValue()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L100)

```php
public function bindValue(string|int $param, mixed $value, int $type): bool
```

Intercepts parameter binding to track values for cache-key construction.

| Param | Type | Description |
|---|---|---|
| `$param` | `string\|int` | Column name or positional index. |
| `$value` | `mixed` | Value to bind. |
| `$type` | `int` | PDO::PARAM_* constant. |

**Returns:** `bool`

</details>

<details>
<summary><code>execute()</code></summary>

#### `CachingStatement execute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L112)

```php
public function execute(?array $params): bool
```

Executes the statement with transparent cache read-through for SELECTs.

| Param | Type | Description |
|---|---|---|
| `$params` | `array<mixed>\|null` | Bound values, overrides any prior bindValue() calls. |

**Returns:** `bool`

</details>

<details>
<summary><code>fetchAll()</code></summary>

#### `CachingStatement fetchAll()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L169)

```php
public function fetchAll(int $mode, mixed $args): array
```

Returns cached rows on a hit, or fetches and stores them on a miss.

| Param | Type | Description |
|---|---|---|
| `$mode` | `int` | PDO::FETCH_* mode. |
| `$args` | `mixed` | — |

**Returns:** `list<mixed>`

</details>

<details>
<summary><code>buildKey()</code></summary>

#### `CachingStatement buildKey()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L199)

```php
private function buildKey(string $sql, array $params): string
```

Builds a deterministic cache key from the SQL and its bound parameters. No version numbers are embedded — invalidation is handled by tag deletion rather than key rotation, so the key remains stable across writes.

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | — |
| `$params` | `array<mixed>` | — |

**Returns:** `string`

</details>

<details>
<summary><code>reset()</code></summary>

#### `CachingStatement reset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/CachingStatement.php#L207)

```php
private function reset(): void
```

Resets all per-execution state before each execute() call.

</details>

## `ArrayQueryCacheDriver`

*final class* — `rafalmasiarek\DashboardKit\Cache\Driver\ArrayQueryCacheDriver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/ArrayQueryCacheDriver.php#L19)

In-memory per-request query cache driver backed by a plain PHP array. Entries exist only for the lifetime of the current request and are never persisted. Useful for tests, for reducing repeated identical queries within a single request when a persistent cache backend is unavailable, or as a first-level cache in front of a slower driver.

implements [`QueryCacheDriverInterface`](#querycachedriverinterface)

**Package:** `rafalmasiarek\DashboardKit\Cache`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$store` | `array<string, array{rows: array<mixed>, tags: list<string>, expires_at: int}>` | private | Internal store keyed by cache key. Each entry: ['rows' => list<mixed>, 'tags' => list<string>, 'expires_at' => int] |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`get()`](#arrayquerycachedriver-get) | public | `array<mixed>\|null` | Returns cached rows if the entry exists and has not expired. |
| [`set()`](#arrayquerycachedriver-set) | public | `void` | Stores a row set with the given tags and TTL. |
| [`invalidate()`](#arrayquerycachedriver-invalidate) | public | `void` | Removes all entries whose tag set intersects with the given table names. |
| [`flush()`](#arrayquerycachedriver-flush) | public | `void` | Removes all entries from the in-memory store. |

<details>
<summary><code>get()</code></summary>

#### `ArrayQueryCacheDriver get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/ArrayQueryCacheDriver.php#L36)

```php
public function get(string $key): ?array
```

Returns cached rows if the entry exists and has not expired.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |

**Returns:** `array<mixed>\|null` — Cached row set, or null on miss or expiry.

</details>

<details>
<summary><code>set()</code></summary>

#### `ArrayQueryCacheDriver set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/ArrayQueryCacheDriver.php#L55)

```php
public function set(string $key, array $rows, int $ttl, string $tags): void
```

Stores a row set with the given tags and TTL.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |
| `$rows` | `array<mixed>` | Row set to cache. |
| `$ttl` | `int` | Time-to-live in seconds. |
| `$tags` | `string` | — |

</details>

<details>
<summary><code>invalidate()</code></summary>

#### `ArrayQueryCacheDriver invalidate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/ArrayQueryCacheDriver.php#L69)

```php
public function invalidate(string $tags): void
```

Removes all entries whose tag set intersects with the given table names.

| Param | Type | Description |
|---|---|---|
| `$tags` | `string` | — |

</details>

<details>
<summary><code>flush()</code></summary>

#### `ArrayQueryCacheDriver flush()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/ArrayQueryCacheDriver.php#L90)

```php
public function flush(): void
```

Removes all entries from the in-memory store.

</details>

## `NullQueryCacheDriver`

*final class* — `rafalmasiarek\DashboardKit\Cache\Driver\NullQueryCacheDriver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/NullQueryCacheDriver.php#L18)

No-op query cache driver that never stores or returns anything. Useful in environments where caching is undesirable (e.g. CLI commands, test suites, or deployments without a suitable cache backend). Every read returns null (cache miss), and write operations are silently discarded.

implements [`QueryCacheDriverInterface`](#querycachedriverinterface)

**Package:** `rafalmasiarek\DashboardKit\Cache`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`get()`](#nullquerycachedriver-get) | public | `array<mixed>\|null` | Always reports a cache miss. |
| [`set()`](#nullquerycachedriver-set) | public | `void` | Discards the row set without storing it. |
| [`invalidate()`](#nullquerycachedriver-invalidate) | public | `void` | No-op invalidation. |
| [`flush()`](#nullquerycachedriver-flush) | public | `void` | No-op flush. |

<details>
<summary><code>get()</code></summary>

#### `NullQueryCacheDriver get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/NullQueryCacheDriver.php#L26)

```php
public function get(string $key): ?array
```

Always reports a cache miss.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |

**Returns:** `array<mixed>\|null` — Always null.

</details>

<details>
<summary><code>set()</code></summary>

#### `NullQueryCacheDriver set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/NullQueryCacheDriver.php#L39)

```php
public function set(string $key, array $rows, int $ttl, string $tags): void
```

Discards the row set without storing it.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |
| `$rows` | `array<mixed>` | Row set to discard. |
| `$ttl` | `int` | Time-to-live in seconds (ignored). |
| `$tags` | `string` | — |

</details>

<details>
<summary><code>invalidate()</code></summary>

#### `NullQueryCacheDriver invalidate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/NullQueryCacheDriver.php#L48)

```php
public function invalidate(string $tags): void
```

No-op invalidation.

| Param | Type | Description |
|---|---|---|
| `$tags` | `string` | — |

</details>

<details>
<summary><code>flush()</code></summary>

#### `NullQueryCacheDriver flush()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/NullQueryCacheDriver.php#L55)

```php
public function flush(): void
```

No-op flush.

</details>

## `PdoQueryCacheDriver`

*final class* — `rafalmasiarek\DashboardKit\Cache\Driver\PdoQueryCacheDriver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L27)

Database-backed tag-based query result cache stored in `_query_cache`. Uses a raw PDO connection (never CachingPdo) to avoid a circular dependency. The backing table is created automatically on first use if absent. Each row stores the serialized row set (payload), a JSON array of table tags, and an expiry timestamp. Invalidation deletes all rows whose tags overlap with the given set — no version counters, no stale-key accumulation. MySQL and SQLite are both supported; the driver auto-detects the engine from PDO::ATTR_DRIVER_NAME and adjusts its queries accordingly.

implements [`QueryCacheDriverInterface`](#querycachedriverinterface)

**Depends on:** `PDO`, [`ClockInterface`](#clockinterface)

**Package:** `rafalmasiarek\DashboardKit\Cache`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$driver` | `string` | private | Detected PDO driver name ('mysql' or 'sqlite'). |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#pdoquerycachedriver-__construct) | public | — | — |
| [`get()`](#pdoquerycachedriver-get) | public | `array<mixed>\|null` | Retrieves cached rows by key if the entry exists and has not expired. |
| [`set()`](#pdoquerycachedriver-set) | public | `void` | Stores a row set with associated tags and a time-to-live. The payload JSON embeds diagnostic metadata (version, timestamps, TTL, table list, row count, column names) alongside the rows for introspection. The tags column is stored separately as a JSON array for SQL-level filtering during invalidation. |
| [`invalidate()`](#pdoquerycachedriver-invalidate) | public | `void` | Deletes all cache entries tagged with any of the given table names. No-op when called with no tags, preventing accidental full-table deletes. |
| [`flush()`](#pdoquerycachedriver-flush) | public | `void` | Removes all entries from the cache table unconditionally. |
| [`prune()`](#pdoquerycachedriver-prune) | public | `int` | Deletes expired cache entries and returns the number of rows removed. Wire this into any scheduled task (e.g. daily cron via dashboard-kit-scheduler) to prevent unbounded table growth from naturally expiring entries that were never explicitly invalidated. |
| [`ensureTable()`](#pdoquerycachedriver-ensuretable) | private | `void` | Creates or migrates the `_query_cache` table. If the table is absent, it is created with the current schema. If the table exists but is missing the `tags` column (schema from the pre-tag-based version), it is dropped and recreated. Cache data is ephemeral, so the drop is safe. |

<details>
<summary><code>__construct()</code></summary>

#### `PdoQueryCacheDriver __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L40)

```php
public function __construct(PDO $pdo, ?ClockInterface $clock)
```

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | Raw database connection. Must not be a CachingPdo instance. |
| `$clock` | `ClockInterface\|null` | Clock for cached_at/expires_at metadata. Defaults to SystemClock. |

</details>

<details>
<summary><code>get()</code></summary>

#### `PdoQueryCacheDriver get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L54)

```php
public function get(string $key): ?array
```

Retrieves cached rows by key if the entry exists and has not expired.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |

**Returns:** `array<mixed>\|null` — Cached row set, or null on miss or expiry.

</details>

<details>
<summary><code>set()</code></summary>

#### `PdoQueryCacheDriver set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L87)

```php
public function set(string $key, array $rows, int $ttl, string $tags): void
```

Stores a row set with associated tags and a time-to-live. The payload JSON embeds diagnostic metadata (version, timestamps, TTL, table list, row count, column names) alongside the rows for introspection. The tags column is stored separately as a JSON array for SQL-level filtering during invalidation.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |
| `$rows` | `array<mixed>` | Row set to cache. |
| `$ttl` | `int` | Time-to-live in seconds. |
| `$tags` | `string` | — |

</details>

<details>
<summary><code>invalidate()</code></summary>

#### `PdoQueryCacheDriver invalidate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L136)

```php
public function invalidate(string $tags): void
```

Deletes all cache entries tagged with any of the given table names. No-op when called with no tags, preventing accidental full-table deletes.

| Param | Type | Description |
|---|---|---|
| `$tags` | `string` | — |

</details>

<details>
<summary><code>flush()</code></summary>

#### `PdoQueryCacheDriver flush()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L159)

```php
public function flush(): void
```

Removes all entries from the cache table unconditionally.

</details>

<details>
<summary><code>prune()</code></summary>

#### `PdoQueryCacheDriver prune()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L173)

```php
public function prune(): int
```

Deletes expired cache entries and returns the number of rows removed. Wire this into any scheduled task (e.g. daily cron via dashboard-kit-scheduler) to prevent unbounded table growth from naturally expiring entries that were never explicitly invalidated.

**Returns:** `int` — Number of deleted rows.

</details>

<details>
<summary><code>ensureTable()</code></summary>

#### `PdoQueryCacheDriver ensureTable()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/Driver/PdoQueryCacheDriver.php#L192)

```php
private function ensureTable(): void
```

Creates or migrates the `_query_cache` table. If the table is absent, it is created with the current schema. If the table exists but is missing the `tags` column (schema from the pre-tag-based version), it is dropped and recreated. Cache data is ephemeral, so the drop is safe.

</details>

## `QueryCacheDriverInterface`

*interface* — `rafalmasiarek\DashboardKit\Cache\QueryCacheDriverInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/QueryCacheDriverInterface.php#L17)

Contract for tag-based query result cache drivers. Each SELECT result is stored under a key derived from the SQL and its bound parameters, tagged with the names of the tables the query reads. On any write to a table, the driver deletes every entry tagged with that table name — providing automatic, targeted cache invalidation without version counters.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`get()`](#querycachedriverinterface-get) | public | `array<mixed>\|null` | Retrieves cached rows by key. Returns null on a cache miss (entry absent or expired). |
| [`set()`](#querycachedriverinterface-set) | public | `void` | Stores a row set under the given key with the supplied tags and TTL. If an entry already exists for the key, it is overwritten. |
| [`invalidate()`](#querycachedriverinterface-invalidate) | public | `void` | Deletes all cache entries tagged with any of the given table names. Called after every write (INSERT / UPDATE / DELETE / REPLACE) outside a transaction, and once per transaction on commit with the union of all tables written during that transaction. |
| [`flush()`](#querycachedriverinterface-flush) | public | `void` | Removes all entries from the cache store unconditionally. Useful for admin panel "clear cache" actions or test teardown. |

<details>
<summary><code>get()</code></summary>

#### `QueryCacheDriverInterface get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/QueryCacheDriverInterface.php#L27)

```php
public function get(string $key): ?array
```

Retrieves cached rows by key. Returns null on a cache miss (entry absent or expired).

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key produced by CachingStatement::buildKey(). |

**Returns:** `array<mixed>\|null` — Cached row set, or null on miss.

</details>

<details>
<summary><code>set()</code></summary>

#### `QueryCacheDriverInterface set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/QueryCacheDriverInterface.php#L39)

```php
public function set(string $key, array $rows, int $ttl, string $tags): void
```

Stores a row set under the given key with the supplied tags and TTL. If an entry already exists for the key, it is overwritten.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Cache key. |
| `$rows` | `array<mixed>` | Row set returned by fetchAll(). |
| `$ttl` | `int` | Time-to-live in seconds. |
| `$tags` | `string` | — |

</details>

<details>
<summary><code>invalidate()</code></summary>

#### `QueryCacheDriverInterface invalidate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/QueryCacheDriverInterface.php#L50)

```php
public function invalidate(string $tags): void
```

Deletes all cache entries tagged with any of the given table names. Called after every write (INSERT / UPDATE / DELETE / REPLACE) outside a transaction, and once per transaction on commit with the union of all tables written during that transaction.

| Param | Type | Description |
|---|---|---|
| `$tags` | `string` | — |

</details>

<details>
<summary><code>flush()</code></summary>

#### `QueryCacheDriverInterface flush()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/QueryCacheDriverInterface.php#L57)

```php
public function flush(): void
```

Removes all entries from the cache store unconditionally. Useful for admin panel "clear cache" actions or test teardown.

</details>

## `SqlTableExtractor`

*final class* — `rafalmasiarek\DashboardKit\Cache\SqlTableExtractor` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L20)

Extracts table names and write intent from a SQL statement. Tokenizer-based, no external dependencies. Handles SELECT/INSERT/UPDATE/DELETE/REPLACE including subqueries, JOIN qualifiers, comma-separated FROM lists, backtick-quoted identifiers, and qualified column references (table.column). DDL statements (CREATE, ALTER, DROP, TRUNCATE, RENAME) are detected early and returned as non-write with an empty table list, preventing misidentification of DDL keywords inside column definitions as table names or write operations.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`extract()`](#sqltableextractor-extract) | public | `array{tables: list<string>, is_write: bool}` | Extract table names and write status from a SQL statement. Join qualifiers (LEFT, INNER, OUTER, etc.) that appear before JOIN are skipped naturally because they are not in TABLE_INTRO and are treated as no-ops by the outer scanner. Subqueries are handled transparently: the scanner processes all tokens linearly, so tables inside subqueries are found when their own FROM/JOIN keywords are reached. |
| [`stripLiterals()`](#sqltableextractor-stripliterals) | private | `string` | Replace single- and double-quoted string literals with empty placeholders. Prevents SQL keywords inside string values from being misread as table-intro keywords (e.g. a value like "FROM users" inside a LIKE clause). |
| [`stripComments()`](#sqltableextractor-stripcomments) | private | `string` | Remove SQL line comments (--) and block comments (/* … * /). |
| [`tokenize()`](#sqltableextractor-tokenize) | private | `list<string>` | Split the SQL into meaningful tokens. Matches (in priority order): 1. Backtick-quoted identifiers: `table_name` 2. Qualified references (col or tbl.col) — dot notation kept together so that `users.id` is one token and rejected by isIdentifier (dot present). 3. Parentheses and commas — structural delimiters. |
| [`unquote()`](#sqltableextractor-unquote) | private | `string` | Strip surrounding backticks from a token. |
| [`isIdentifier()`](#sqltableextractor-isidentifier) | private | `bool` | Return true when the (already unquoted) token is a valid table or alias name. Rejects SQL reserved words and qualified references (tokens containing a dot, which are column references like users.id). |

<details>
<summary><code>extract()</code></summary>

#### `SqlTableExtractor extract()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L73)

```php
public function extract(string $sql): array
```

Extract table names and write status from a SQL statement. Join qualifiers (LEFT, INNER, OUTER, etc.) that appear before JOIN are skipped naturally because they are not in TABLE_INTRO and are treated as no-ops by the outer scanner. Subqueries are handled transparently: the scanner processes all tokens linearly, so tables inside subqueries are found when their own FROM/JOIN keywords are reached.

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | Raw SQL, may contain ? or :name placeholders. |

**Returns:** `array{tables: list<string>, is_write: bool}`

</details>

<details>
<summary><code>stripLiterals()</code></summary>

#### `SqlTableExtractor stripLiterals()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L162)

```php
private function stripLiterals(string $sql): string
```

Replace single- and double-quoted string literals with empty placeholders. Prevents SQL keywords inside string values from being misread as table-intro keywords (e.g. a value like "FROM users" inside a LIKE clause).

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>stripComments()</code></summary>

#### `SqlTableExtractor stripComments()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L175)

```php
private function stripComments(string $sql): string
```

Remove SQL line comments (--) and block comments (/* … * /).

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>tokenize()</code></summary>

#### `SqlTableExtractor tokenize()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L194)

```php
private function tokenize(string $sql): array
```

Split the SQL into meaningful tokens. Matches (in priority order): 1. Backtick-quoted identifiers: `table_name` 2. Qualified references (col or tbl.col) — dot notation kept together so that `users.id` is one token and rejected by isIdentifier (dot present). 3. Parentheses and commas — structural delimiters.

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | — |

**Returns:** `list<string>`

</details>

<details>
<summary><code>unquote()</code></summary>

#### `SqlTableExtractor unquote()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L211)

```php
private function unquote(string $token): string
```

Strip surrounding backticks from a token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>isIdentifier()</code></summary>

#### `SqlTableExtractor isIdentifier()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/SqlTableExtractor.php#L227)

```php
private function isIdentifier(string $name): bool
```

Return true when the (already unquoted) token is a valid table or alias name. Rejects SQL reserved words and qualified references (tokens containing a dot, which are column references like users.id).

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Unquoted token. |

**Returns:** `bool`

</details>

## `TransactionState`

*final class* — `rafalmasiarek\DashboardKit\Cache\TransactionState` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Cache/TransactionState.php#L20)

Shared mutable transaction state passed between CachingPdo and CachingStatement. Acts as a coordination bag: CachingPdo sets $active on begin/commit/rollback; CachingStatement reads $active to decide whether to skip the cache and appends to $dirtyTables instead of bumping immediately. CachingPdo flushes accumulated dirty tables to TableVersionTracker on commit and discards them on rollback. Passed by object handle so mutations in CachingPdo are immediately visible in every CachingStatement prepared from the same connection.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$active` | `bool` | public | Whether a PDO transaction is currently active. |
| `$dirtyTables` | `list<string>` | public | Tables touched by writes during the active transaction. Accumulated by CachingStatement and CachingPdo::exec() during a transaction. Flushed to TableVersionTracker on commit; discarded on rollback. |

## `AuthController`

*class* — `rafalmasiarek\DashboardKit\Controllers\AuthController` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L23)

Handles login, registration and logout flows.

**Depends on:** `Twig`, `Auth`, [`Flash`](#flash), [`HookRegistry`](#hookregistry), [`AuditLog`](#auditlog), `PDO`, [`MailerInterface`](#mailerinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#authcontroller-__construct) | public | — | — |
| [`login()`](#authcontroller-login) | public | `Response` | Renders the login form (GET) or processes credentials (POST). Reads a ?from= query parameter (GET) or a hidden 'from' field (POST) and redirects there after successful login instead of the default '/'. Only same-origin paths are accepted (must start with '/' but not '//'). |
| [`register()`](#authcontroller-register) | public | `Response` | Renders the registration form (GET) or creates a new account (POST). On successful registration a UUID v4 is assigned to the new user row. If password_strength is enabled the password is scored server-side before the account is created; scores below min_score are rejected with an error. |
| [`logout()`](#authcontroller-logout) | public | `Response` | Destroys the current session and redirects to /login. |
| [`sendActivationEmail()`](#authcontroller-sendactivationemail) | private | `void` | Generates an activation token, stores it, and sends the activation email. When the mailer is not configured, only the token is stored; no email is sent. |
| [`baseUrl()`](#authcontroller-baseurl) | private | `string` | Derives the base URL (scheme + host + optional port) from the request. |

<details>
<summary><code>__construct()</code></summary>

#### `AuthController __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L45)

```php
public function __construct(Twig $view, Auth $auth, Flash $flash, array $userFields, HookRegistry $hooks, AuditLog $audit, PDO $db, array $passwordStrength, bool $requireActivation, ?MailerInterface $mailer, mixed $beforeLogin, mixed $beforeRegister, string $dashboardUrlPrefix)
```

| Param | Type | Description |
|---|---|---|
| `$view` | `Twig` | Twig rendering engine. |
| `$auth` | `Auth` | AuthKit authentication service. |
| `$flash` | [`Flash`](#flash) | Flash message store. |
| `$userFields` | `array<string, array{label: string, type: string, max: int, required: bool}>` | Extra profile fields shown on the register form. |
| `$hooks` | [`HookRegistry`](#hookregistry) | Event hook registry. |
| `$audit` | [`AuditLog`](#auditlog) | Audit logger. |
| `$db` | `PDO` | Database connection — used to assign UUID on registration. |
| `$passwordStrength` | `array<string, mixed>` | Resolved password-strength config (enabled, min_score, rules). |
| `$requireActivation` | `bool` | When true, sends an activation email after registration. |
| `$mailer` | `MailerInterface\|null` | Mailer instance; required when requireActivation is true to send emails. |
| `$beforeLogin` | `callable\|null` | Called before credential check on POST /login. Signature: (Request): ?string — return null to pass, string to block with that error. Set via config key 'before_login' or $container->set('auth.before_login', callable). |
| `$beforeRegister` | `callable\|null` | Called before account creation on POST /register. Same signature as $beforeLogin. Set via config key 'before_register' or $container->set('auth.before_register', callable). |
| `$dashboardUrlPrefix` | `string` | Full URL prefix prepended to all dashboard redirects (e.g. '/api/admin'). Combines app.base_path and dashboard.prefix from config. |

</details>

<details>
<summary><code>login()</code></summary>

#### `AuthController login()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L73)

```php
public function login(Request $request, Response $response): Response
```

Renders the login form (GET) or processes credentials (POST). Reads a ?from= query parameter (GET) or a hidden 'from' field (POST) and redirects there after successful login instead of the default '/'. Only same-origin paths are accepted (must start with '/' but not '//').

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | Incoming request. |
| `$response` | `Response` | PSR-7 response. |

**Returns:** `Response`

</details>

<details>
<summary><code>register()</code></summary>

#### `AuthController register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L129)

```php
public function register(Request $request, Response $response): Response
```

Renders the registration form (GET) or creates a new account (POST). On successful registration a UUID v4 is assigned to the new user row. If password_strength is enabled the password is scored server-side before the account is created; scores below min_score are rejected with an error.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | Incoming request. |
| `$response` | `Response` | PSR-7 response. |

**Returns:** `Response`

</details>

<details>
<summary><code>logout()</code></summary>

#### `AuthController logout()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L215)

```php
public function logout(Request $request, Response $response): Response
```

Destroys the current session and redirects to /login.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | Incoming request. |
| `$response` | `Response` | PSR-7 response. |

**Returns:** `Response`

</details>

<details>
<summary><code>sendActivationEmail()</code></summary>

#### `AuthController sendActivationEmail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L235)

```php
private function sendActivationEmail(\AuthKit\User $user, Request $request): void
```

Generates an activation token, stores it, and sends the activation email. When the mailer is not configured, only the token is stored; no email is sent.

| Param | Type | Description |
|---|---|---|
| `$user` | `\AuthKit\User` | Newly registered user. |
| `$request` | `Request` | Current HTTP request (used to build the activation URL). |

</details>

<details>
<summary><code>baseUrl()</code></summary>

#### `AuthController baseUrl()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/AuthController.php#L266)

```php
private function baseUrl(Request $request): string
```

Derives the base URL (scheme + host + optional port) from the request.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |

**Returns:** `string`

</details>

## `PasswordResetController`

*class* — `rafalmasiarek\DashboardKit\Controllers\PasswordResetController` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L32)

Handles the forgot-password and password-reset flows. Routes (all public, no auth required): GET  /forgot-password          — render the "enter your email" form POST /forgot-password          — generate token and send reset email GET  /reset-password/{token}   — render the "choose new password" form POST /reset-password/{token}   — validate token and update password Hooks emitted: password_reset_requested (string $email) password_reset_completed (\AuthKit\User $user)

**Depends on:** `Twig`, `Auth`, [`Flash`](#flash), `PDO`, [`HookRegistry`](#hookregistry), [`AuditLog`](#auditlog), [`MailerInterface`](#mailerinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#passwordresetcontroller-__construct) | public | — | — |
| [`requestForm()`](#passwordresetcontroller-requestform) | public | `Response` | Renders the forgot-password form. |
| [`request()`](#passwordresetcontroller-request) | public | `Response` | Processes the forgot-password form. Always responds with a neutral flash to avoid revealing whether the email is registered. When the email exists: generates a token, sends the reset email (or logs the link), and emits the password_reset_requested hook. |
| [`resetForm()`](#passwordresetcontroller-resetform) | public | `Response` | Renders the reset-password form for a given token. Validates the token before rendering — redirects to /forgot-password on invalid/expired tokens. |
| [`reset()`](#passwordresetcontroller-reset) | public | `Response` | Processes the reset-password form. Validates token, password match and optional strength requirement, then updates the password hash directly (bypassing current-password check), invalidates all existing sessions, and emits the password_reset_completed hook. |
| [`tokenExists()`](#passwordresetcontroller-tokenexists) | private | `bool` | Checks whether a token exists in password_resets and has not yet expired. |
| [`baseUrl()`](#passwordresetcontroller-baseurl) | private | `string` | Derives the base URL (scheme + host + optional port) from the request. |

<details>
<summary><code>__construct()</code></summary>

#### `PasswordResetController __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L48)

```php
public function __construct(Twig $view, Auth $auth, Flash $flash, PDO $db, HookRegistry $hooks, AuditLog $audit, array $passwordStrength, ?MailerInterface $mailer, string $dashboardUrlPrefix)
```

| Param | Type | Description |
|---|---|---|
| `$view` | `Twig` | Twig rendering engine. |
| `$auth` | `Auth` | AuthKit authentication service. |
| `$flash` | [`Flash`](#flash) | Flash message store. |
| `$db` | `PDO` | Database connection. |
| `$hooks` | [`HookRegistry`](#hookregistry) | Event hook registry. |
| `$audit` | [`AuditLog`](#auditlog) | Audit logger. |
| `$passwordStrength` | `array<string, mixed>` | Resolved password-strength config. |
| `$mailer` | `MailerInterface\|null` | Optional mailer — reset link is logged when null. |
| `$dashboardUrlPrefix` | `string` | Full URL prefix for dashboard redirects (e.g. '/api/admin'). |

</details>

<details>
<summary><code>requestForm()</code></summary>

#### `PasswordResetController requestForm()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L68)

```php
public function requestForm(Request $request, Response $response): Response
```

Renders the forgot-password form.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>request()</code></summary>

#### `PasswordResetController request()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L84)

```php
public function request(Request $request, Response $response): Response
```

Processes the forgot-password form. Always responds with a neutral flash to avoid revealing whether the email is registered. When the email exists: generates a token, sends the reset email (or logs the link), and emits the password_reset_requested hook.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>resetForm()</code></summary>

#### `PasswordResetController resetForm()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L135)

```php
public function resetForm(Request $request, Response $response, array $args): Response
```

Renders the reset-password form for a given token. Validates the token before rendering — redirects to /forgot-password on invalid/expired tokens.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (token). |

**Returns:** `Response`

</details>

<details>
<summary><code>reset()</code></summary>

#### `PasswordResetController reset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L159)

```php
public function reset(Request $request, Response $response, array $args): Response
```

Processes the reset-password form. Validates token, password match and optional strength requirement, then updates the password hash directly (bypassing current-password check), invalidates all existing sessions, and emits the password_reset_completed hook.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (token). |

**Returns:** `Response`

</details>

<details>
<summary><code>tokenExists()</code></summary>

#### `PasswordResetController tokenExists()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L218)

```php
private function tokenExists(string $token): bool
```

Checks whether a token exists in password_resets and has not yet expired.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>baseUrl()</code></summary>

#### `PasswordResetController baseUrl()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/PasswordResetController.php#L233)

```php
private function baseUrl(Request $request): string
```

Derives the base URL (scheme + host + optional port) from the request.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |

**Returns:** `string`

</details>

## `SettingsController`

*class* — `rafalmasiarek\DashboardKit\Controllers\SettingsController` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/SettingsController.php#L21)

Handles the current user's account settings: password, email changes, and custom profile fields. Routes are registered internally by Dashboard and protected by AuthMiddleware.

**Depends on:** `Twig`, `Auth`, [`Flash`](#flash), `PDO`, [`HookRegistry`](#hookregistry), [`AuditLog`](#auditlog)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#settingscontroller-__construct) | public | — | — |
| [`index()`](#settingscontroller-index) | public | `Response` | Renders the settings page. |
| [`changePassword()`](#settingscontroller-changepassword) | public | `Response` | Processes a password change request. Validates current password before applying the new one. |
| [`changeEmail()`](#settingscontroller-changeemail) | public | `Response` | Processes an email address change request. Rejects the new address if it is already taken by another account. |
| [`updateProfile()`](#settingscontroller-updateprofile) | public | `Response` | Processes a profile fields update (all user_fields at once). Validates required fields before saving. Empty optional values are stored as NULL. |

<details>
<summary><code>__construct()</code></summary>

#### `SettingsController __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/SettingsController.php#L33)

```php
public function __construct(Twig $view, Auth $auth, Flash $flash, PDO $db, array $userFields, HookRegistry $hooks, AuditLog $audit, string $dashboardUrlPrefix)
```

| Param | Type | Description |
|---|---|---|
| `$view` | `Twig` | Twig rendering engine. |
| `$auth` | `Auth` | AuthKit authentication service. |
| `$flash` | [`Flash`](#flash) | Flash message store. |
| `$db` | `PDO` | Database connection. |
| `$userFields` | `array<string, array{label: string, type: string, max: int, required: bool}>` | Extra profile fields. |
| `$hooks` | [`HookRegistry`](#hookregistry) | Event hook registry. |
| `$audit` | [`AuditLog`](#auditlog) | Audit logger. |
| `$dashboardUrlPrefix` | `string` | Full URL prefix for dashboard routes (base_path + dashboard.prefix). |

</details>

<details>
<summary><code>index()</code></summary>

#### `SettingsController index()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/SettingsController.php#L52)

```php
public function index(Request $request, Response $response): Response
```

Renders the settings page.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>changePassword()</code></summary>

#### `SettingsController changePassword()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/SettingsController.php#L78)

```php
public function changePassword(Request $request, Response $response): Response
```

Processes a password change request. Validates current password before applying the new one.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>changeEmail()</code></summary>

#### `SettingsController changeEmail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/SettingsController.php#L125)

```php
public function changeEmail(Request $request, Response $response): Response
```

Processes an email address change request. Rejects the new address if it is already taken by another account.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>updateProfile()</code></summary>

#### `SettingsController updateProfile()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/SettingsController.php#L173)

```php
public function updateProfile(Request $request, Response $response): Response
```

Processes a profile fields update (all user_fields at once). Validates required fields before saving. Empty optional values are stored as NULL.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

## `UserController`

*class* — `rafalmasiarek\DashboardKit\Controllers\UserController` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L21)

Handles admin user management: listing, editing, role changes, suspend, unsuspend, and delete. Routes are registered internally by Dashboard and protected by admin RoleMiddleware.

**Depends on:** `Twig`, `Auth`, [`Flash`](#flash), `PDO`, [`HookRegistry`](#hookregistry), [`AuditLog`](#auditlog)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#usercontroller-__construct) | public | — | — |
| [`list()`](#usercontroller-list) | public | `Response` | Renders the user list with search, sortable columns and pagination. Query parameters: q — search term (matches email) sort — column name; allowed: id, email, role, active, suspended_at dir — asc or desc page — page number (1-based) |
| [`edit()`](#usercontroller-edit) | public | `Response` | Renders the edit form for a single user, looked up by UUID. |
| [`update()`](#usercontroller-update) | public | `Response` | Processes the edit form — updates email, role, active flag and all custom profile fields. User is looked up by UUID; all DB writes use the internal integer ID. Fires the user_updated hook with the target user and the admin who made the change. |
| [`delete()`](#usercontroller-delete) | public | `Response` | Permanently deletes a user account and invalidates all their sessions. An admin cannot delete their own account. User is looked up by UUID; deletion uses the internal integer ID. Fires the user_deleted hook with the target user and the admin. |
| [`suspend()`](#usercontroller-suspend) | public | `Response` | Suspends a user and immediately invalidates all their sessions. An admin cannot suspend themselves. User is looked up by UUID; DB writes use the internal integer ID. |
| [`unsuspend()`](#usercontroller-unsuspend) | public | `Response` | Removes the suspension from a user, allowing them to log in again. User is looked up by UUID; DB writes use the internal integer ID. |
| [`createForm()`](#usercontroller-createform) | public | `Response` | Renders the create-user form. |
| [`create()`](#usercontroller-create) | public | `Response` | Processes the create-user form and creates a new active account. Admin-created accounts are immediately active regardless of the require_activation setting. A UUID is assigned and the user_created audit event is fired. |
| [`fetchUserRowById()`](#usercontroller-fetchuserrowbyid) | private | `array<string, mixed>\|null` | Fetches a single user row by ID (UUID), including all custom profile columns. Returns null when no user with that ID exists. |
| [`fetchUser()`](#usercontroller-fetchuser) | private | `\AuthKit\User\|null` | Fetches a user row and wraps it in a User object. Returns null when the user does not exist. |
| [`paginationRange()`](#usercontroller-paginationrange) | private static | `array<int, int\|string>` | Computes the visible page numbers for the pagination bar. Always includes the first and last page. Shows a window of ±2 around the current page. Inserts '...' strings between non-adjacent groups. |

<details>
<summary><code>__construct()</code></summary>

#### `UserController __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L37)

```php
public function __construct(Twig $view, Auth $auth, Flash $flash, PDO $db, array $userFields, HookRegistry $hooks, AuditLog $audit, bool $requireActivation, string $adminPanelUrlPrefix)
```

| Param | Type | Description |
|---|---|---|
| `$view` | `Twig` | Twig rendering engine. |
| `$auth` | `Auth` | AuthKit authentication service. |
| `$flash` | [`Flash`](#flash) | Flash message store. |
| `$db` | `PDO` | Database connection. |
| `$userFields` | `array<string, array{label: string, type: string, max: int, required: bool}>` | Configured custom profile fields. |
| `$hooks` | [`HookRegistry`](#hookregistry) | Event hook registry. |
| `$audit` | [`AuditLog`](#auditlog) | Audit logger. |
| `$requireActivation` | `bool` | When true, the active column exists and is exposed in list/edit UI. |
| `$adminPanelUrlPrefix` | `string` | Full URL prefix for the admin panel (base_path + dashboard.prefix + dashboard.admin_prefix). |

</details>

<details>
<summary><code>list()</code></summary>

#### `UserController list()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L63)

```php
public function list(Request $request, Response $response): Response
```

Renders the user list with search, sortable columns and pagination. Query parameters: q    — search term (matches email) sort — column name; allowed: id, email, role, active, suspended_at dir  — asc or desc page — page number (1-based)

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>edit()</code></summary>

#### `UserController edit()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L114)

```php
public function edit(Request $request, Response $response, array $args): Response
```

Renders the edit form for a single user, looked up by UUID.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (id). |

**Returns:** `Response`

</details>

<details>
<summary><code>update()</code></summary>

#### `UserController update()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L144)

```php
public function update(Request $request, Response $response, array $args): Response
```

Processes the edit form — updates email, role, active flag and all custom profile fields. User is looked up by UUID; all DB writes use the internal integer ID. Fires the user_updated hook with the target user and the admin who made the change.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (id). |

**Returns:** `Response`

</details>

<details>
<summary><code>delete()</code></summary>

#### `UserController delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L205)

```php
public function delete(Request $request, Response $response, array $args): Response
```

Permanently deletes a user account and invalidates all their sessions. An admin cannot delete their own account. User is looked up by UUID; deletion uses the internal integer ID. Fires the user_deleted hook with the target user and the admin.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (id). |

**Returns:** `Response`

</details>

<details>
<summary><code>suspend()</code></summary>

#### `UserController suspend()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L245)

```php
public function suspend(Request $request, Response $response, array $args): Response
```

Suspends a user and immediately invalidates all their sessions. An admin cannot suspend themselves. User is looked up by UUID; DB writes use the internal integer ID.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (id). |

**Returns:** `Response`

</details>

<details>
<summary><code>unsuspend()</code></summary>

#### `UserController unsuspend()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L283)

```php
public function unsuspend(Request $request, Response $response, array $args): Response
```

Removes the suspension from a user, allowing them to log in again. User is looked up by UUID; DB writes use the internal integer ID.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |
| `$args` | `array<string, string>` | Route arguments (id). |

**Returns:** `Response`

</details>

<details>
<summary><code>createForm()</code></summary>

#### `UserController createForm()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L312)

```php
public function createForm(Request $request, Response $response): Response
```

Renders the create-user form.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>create()</code></summary>

#### `UserController create()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L336)

```php
public function create(Request $request, Response $response): Response
```

Processes the create-user form and creates a new active account. Admin-created accounts are immediately active regardless of the require_activation setting. A UUID is assigned and the user_created audit event is fired.

| Param | Type | Description |
|---|---|---|
| `$request` | `Request` | — |
| `$response` | `Response` | — |

**Returns:** `Response`

</details>

<details>
<summary><code>fetchUserRowById()</code></summary>

#### `UserController fetchUserRowById()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L414)

```php
private function fetchUserRowById(string $id): ?array
```

Fetches a single user row by ID (UUID), including all custom profile columns. Returns null when no user with that ID exists.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | User's UUID primary key. |

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>fetchUser()</code></summary>

#### `UserController fetchUser()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L434)

```php
private function fetchUser(int|string $id): ?\AuthKit\User
```

Fetches a user row and wraps it in a User object. Returns null when the user does not exist.

| Param | Type | Description |
|---|---|---|
| `$id` | `int\|string` | — |

**Returns:** `\AuthKit\User\|null`

</details>

<details>
<summary><code>paginationRange()</code></summary>

#### `UserController paginationRange()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Controllers/UserController.php#L452)

```php
private static function paginationRange(int $current, int $total): array
```

Computes the visible page numbers for the pagination bar. Always includes the first and last page. Shows a window of ±2 around the current page. Inserts '...' strings between non-adjacent groups.

| Param | Type | Description |
|---|---|---|
| `$current` | `int` | Current page number. |
| `$total` | `int` | Total number of pages. |

**Returns:** `array<int, int\|string>`

</details>

## `Dashboard`

*class* — `rafalmasiarek\DashboardKit\Dashboard` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L86)

Entry point factory for building a dashboard application. Usage: Dashboard::create(__DIR__ . '/../', ['app_name' => 'My App'])->run();

**Depends on:** `App`, `Container`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#dashboard-__construct) | private | — | — |
| [`create()`](#dashboard-create) | public static | `self` | Builds and wires the full Slim application. Supported config keys: app_name (string) Displayed in navbar and page title. Default: 'App'. modules_dir (string) Absolute path to the user modules directory. admin_modules_dir (string) Absolute path to the admin modules directory. Default: {rootDir}/admin_modules. Each subfolder must contain a module.php returning an array with at least 'slug'. Supported keys: slug, title, icon, description, order, navbar, render, handle, routes, settings. 'routes' are registered under /admin/{slug} and protected by admin role. Optional 'settings' key registers a user-level section under /settings/{slug}: title (string) Section title in the settings navigation. order (int) Sort order in settings navigation. Default: 100. templates_dir (string) Absolute path to settings templates; namespace @{slug}-settings. routes (array) Same handler format as module 'routes'. Mounted under /settings/{slug} with AuthMiddleware + CsrfMiddleware only. templates_dir (string) Absolute path to user templates that override built-ins. logs_dir (string) Base directory for log files. Default: {rootDir}/storage/logs. logging (array) Per-channel overrides. Built-in channels: app, audit, error. Each entry: ['path' => 'storage/logs/app.log', 'level' => 'info', 'days' => 30]. Path may be absolute or relative to logs_dir. Extra channels are registered as logger.{name} in the container. Special key request_headers (global or per-channel) injects request headers into every log record for that channel: [] — no injection (default) '*' — all request headers (prefixed req.*) string[] — specific headers, e.g. ['X-Forwarded-For', 'User-Agent'] Global: 'logging' => ['request_headers' => [...], ...] Per-channel:'logging' => ['audit' => ['request_headers' => [...], ...]] env (string) 'dev' enables Whoops; anything else uses Twig error pages. registration (bool) When true, registers the /register route. Default: false. password_reset (bool) When true, registers /forgot-password and /reset-password routes. Default: false. require_activation (bool) When true, new registrations require email activation before login is allowed. Wires /activate/{token}, login check, and activation email (requires mailer to be configured). Default: false. before_login (callable) Called before credentials are checked on POST /login. Signature: (ServerRequestInterface): ?string Return null to allow, or a string error message to block. Can also be overridden after create() via: $dashboard->getContainer()->set('auth.before_login', callable). before_register (callable) Called before account creation on POST /register. Same signature as before_login. Database connection is read from environment variables: DB_HOST, DB_NAME, DB_USER, DB_PASS If rafalmasiarek/dashboard-kit-scheduler is installed, the scheduler addon is wired automatically — no additional configuration needed. |
| [`run()`](#dashboard-run) | public | `void` | Runs the application. Call this as the last statement in public/index.php. |
| [`getApp()`](#dashboard-getapp) | public | `App` | Returns the underlying Slim application for advanced customisation. Allows adding middleware or routes after Dashboard::create(): $dashboard->getApp()->add(new MyMiddleware()); |
| [`on()`](#dashboard-on) | public | `self` | Registers a hook listener for a dashboard event. Supported events and their listener signatures: register (User $user) login (User $user) logout (User $user) password_changed (User $user) email_changed (User $user, string $oldEmail) profile_updated (User $user, array $updatedFields) user_suspended (User $target, User $admin) user_unsuspended (User $target, User $admin) role_changed (User $target, string $oldRole, string $newRole, User $admin) user_updated (User $target, string[] $changedFields, User $admin) user_deleted (User $target, User $admin) |
| [`getContainer()`](#dashboard-getcontainer) | public | `Container` | Returns the DI container for registering additional services. Returns the concrete PHP-DI Container (not ContainerInterface) so that callers can invoke set() to override or extend bindings before run(). |
| [`resolveLogChannels()`](#dashboard-resolvelogchannels) | private static | `array<string, array{path: string, level: string, days: int}>` | Resolves the logging channel configs, merging user overrides with built-in defaults. Default channels and their defaults: app — {logs_dir}/app.log, level: info, days: 30 audit — {logs_dir}/audit.log, level: info, days: 90 error — {logs_dir}/error.log, level: error, days: 30 A path without a leading slash is treated as relative to $logsDir. Extra channels defined by the user are appended after the built-in ones. |
| [`absoluteLogPath()`](#dashboard-absolutelogpath) | private static | `string` | Returns an absolute path for a log file. |
| [`normalizeUserFields()`](#dashboard-normalizeuserfields) | private static | `array<string, array{label: string, type: string, max: int, required: bool, options: array<string, string>}>` | Normalises the user_fields config array to a consistent shape. Each field entry is resolved to: label (string) type (string, default 'text') max (int, default 255) required (bool, default false) options (array<string,string>, only for type='select') A plain string value is treated as the label with all defaults applied. |
| [`buildContainer()`](#dashboard-buildcontainer) | private static | `Container` | Builds and populates the DI container. |
| [`initModuleSchemas()`](#dashboard-initmoduleschemas) | private static | `void` | Syncs all module schemas against the live database via SchemaStateManager. Processes module schemas from both public and admin registries, plus any top-level 'schema' key defined in $config (tables that do not belong to any module — stored under the pseudo-slug '_bootstrap'). Runs before route registration so all tables are guaranteed to exist on the first request. Uses a hash-based state table (_schema_state) to skip tables whose schema has not changed — no INFORMATION_SCHEMA queries on the happy path. 'schema_defaults.timestamps'/'schema_defaults.soft_deletes' in $config set this app's own default for every table that doesn't declare its own 'timestamps'/'soft_deletes' key — both default to false, unchanged from today's behavior, unless this app's config opts in. Deliberately a separate top-level key from 'schema' (reserved for the top-level standalone-table definitions handled below) to avoid colliding with it. |
| [`runSeeds()`](#dashboard-runseeds) | private static | `void` | Runs declarative seed definitions from the 'seed' config key. Seeds are idempotent — each row is skipped when a matching record already exists. Runs after schema sync so all target tables are guaranteed to exist. Uses the raw PDO connection to avoid boot-time interactions with CachingPdo. |
| [`registerRoutes()`](#dashboard-registerroutes) | private static | `void` | Registers all routes: auth, root redirect, module pages and API endpoints. Routes are placed under the dashboard prefix group (config: dashboard.prefix). The inner admin group uses a separate prefix (config: dashboard.admin_prefix). Public-facing routes (modules with auth_required: false) are also inside the dashboard prefix group, consistent with the overall URL structure. |
| [`registerErrorHandlers()`](#dashboard-registererrorhandlers) | private static | `void` | Registers error handlers: Whoops for dev mode, Twig error templates for production. Template lookup hierarchy (Symfony-style): errors/404.twig → errors/4xx.twig → errors/error.twig All exceptions are logged to the error channel before rendering. |
| [`addRoutesMap()`](#dashboard-addroutesmap) | private static | `void` | Registers multiple named routes for a single module. Use the routes key for modules that need full CRUD (list, new, edit, delete) instead of a single render/handle pair. Route definition format: 'METHOD /path' => callable Handler signature: (ServerRequestInterface, ResponseInterface, array $args, Twig, PDO, ContainerInterface): ResponseInterface The $module array must have 'slug' (used as the route prefix) and 'routes'. Callers decide the target group: admin modules call this on the admin group, module settings call this on the /settings group — the method is group-agnostic. Auth and CSRF are applied at the group level, not per-route. |
| [`addModuleRoute()`](#dashboard-addmoduleroute) | private static | `void` | Registers GET and POST routes for a single module. Module callback signatures (5th arg is PSR-11, optional): render: (ServerRequestInterface, ResponseInterface, Twig, PDO, ContainerInterface): ResponseInterface handle: (ServerRequestInterface, ResponseInterface, Twig, PDO, ContainerInterface): ResponseInterface |

<details>
<summary><code>__construct()</code></summary>

#### `Dashboard __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L94)

```php
private function __construct(App $app, Container $container, string $logsDir, array $channels)
```

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | Slim application instance. |
| `$container` | `Container` | PHP-DI container. |
| `$logsDir` | `string` | Absolute path to the logs directory. |
| `$channels` | `array<string, array{path: string, level: string, days: int}>` | Resolved channel configs. |

</details>

<details>
<summary><code>create()</code></summary>

#### `Dashboard create()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L156)

```php
public static function create(string $rootDir, array $config): self
```

Builds and wires the full Slim application. Supported config keys: app_name      (string) Displayed in navbar and page title. Default: 'App'. modules_dir         (string) Absolute path to the user modules directory. admin_modules_dir   (string) Absolute path to the admin modules directory. Default: {rootDir}/admin_modules. Each subfolder must contain a module.php returning an array with at least 'slug'. Supported keys: slug, title, icon, description, order, navbar, render, handle, routes, settings. 'routes' are registered under /admin/{slug} and protected by admin role. Optional 'settings' key registers a user-level section under /settings/{slug}: title         (string) Section title in the settings navigation. order         (int)    Sort order in settings navigation. Default: 100. templates_dir (string) Absolute path to settings templates; namespace @{slug}-settings. routes        (array)  Same handler format as module 'routes'. Mounted under /settings/{slug} with AuthMiddleware + CsrfMiddleware only. templates_dir       (string) Absolute path to user templates that override built-ins. logs_dir            (string) Base directory for log files. Default: {rootDir}/storage/logs. logging             (array)  Per-channel overrides. Built-in channels: app, audit, error. Each entry: ['path' => 'storage/logs/app.log', 'level' => 'info', 'days' => 30]. Path may be absolute or relative to logs_dir. Extra channels are registered as logger.{name} in the container. Special key request_headers (global or per-channel) injects request headers into every log record for that channel: []        — no injection (default) '*'       — all request headers (prefixed req.*) string[]  — specific headers, e.g. ['X-Forwarded-For', 'User-Agent'] Global:     'logging' => ['request_headers' => [...], ...] Per-channel:'logging' => ['audit' => ['request_headers' => [...], ...]] env                 (string) 'dev' enables Whoops; anything else uses Twig error pages. registration         (bool)   When true, registers the /register route. Default: false. password_reset       (bool)   When true, registers /forgot-password and /reset-password routes. Default: false. require_activation   (bool)     When true, new registrations require email activation before login is allowed. Wires /activate/{token}, login check, and activation email (requires mailer to be configured). Default: false. before_login         (callable) Called before credentials are checked on POST /login. Signature: (ServerRequestInterface): ?string Return null to allow, or a string error message to block. Can also be overridden after create() via: $dashboard->getContainer()->set('auth.before_login', callable). before_register      (callable) Called before account creation on POST /register. Same signature as before_login. Database connection is read from environment variables: DB_HOST, DB_NAME, DB_USER, DB_PASS If rafalmasiarek/dashboard-kit-scheduler is installed, the scheduler addon is wired automatically — no additional configuration needed.

| Param | Type | Description |
|---|---|---|
| `$rootDir` | `string` | Absolute path to the application root. |
| `$config` | `array<string, mixed>` | Optional configuration overrides. |

**Returns:** `self`

</details>

<details>
<summary><code>run()</code></summary>

#### `Dashboard run()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L239)

```php
public function run(): void
```

Runs the application. Call this as the last statement in public/index.php.

</details>

<details>
<summary><code>getApp()</code></summary>

#### `Dashboard getApp()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L252)

```php
public function getApp(): App
```

Returns the underlying Slim application for advanced customisation. Allows adding middleware or routes after Dashboard::create(): $dashboard->getApp()->add(new MyMiddleware());

**Returns:** `App`

</details>

<details>
<summary><code>on()</code></summary>

#### `Dashboard on()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L277)

```php
public function on(string $event, callable $listener): self
```

Registers a hook listener for a dashboard event. Supported events and their listener signatures: register         (User $user) login            (User $user) logout           (User $user) password_changed (User $user) email_changed    (User $user, string $oldEmail) profile_updated  (User $user, array $updatedFields) user_suspended   (User $target, User $admin) user_unsuspended (User $target, User $admin) role_changed     (User $target, string $oldRole, string $newRole, User $admin) user_updated     (User $target, string[] $changedFields, User $admin) user_deleted     (User $target, User $admin)

| Param | Type | Description |
|---|---|---|
| `$event` | `string` | Event name. |
| `$listener` | `callable` | Callback invoked after the event fires. |

**Returns:** `self`

</details>

<details>
<summary><code>getContainer()</code></summary>

#### `Dashboard getContainer()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L291)

```php
public function getContainer(): Container
```

Returns the DI container for registering additional services. Returns the concrete PHP-DI Container (not ContainerInterface) so that callers can invoke set() to override or extend bindings before run().

**Returns:** `Container`

</details>

<details>
<summary><code>resolveLogChannels()</code></summary>

#### `Dashboard resolveLogChannels()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L311)

```php
private static function resolveLogChannels(array $raw, string $logsDir): array
```

Resolves the logging channel configs, merging user overrides with built-in defaults. Default channels and their defaults: app   — {logs_dir}/app.log,   level: info,  days: 30 audit — {logs_dir}/audit.log, level: info,  days: 90 error — {logs_dir}/error.log, level: error, days: 30 A path without a leading slash is treated as relative to $logsDir. Extra channels defined by the user are appended after the built-in ones.

| Param | Type | Description |
|---|---|---|
| `$raw` | `array<string, mixed>` | User-supplied 'logging' config key. |
| `$logsDir` | `string` | Resolved absolute logs directory. |

**Returns:** `array<string, array{path: string, level: string, days: int}>`

</details>

<details>
<summary><code>absoluteLogPath()</code></summary>

#### `Dashboard absoluteLogPath()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L348)

```php
private static function absoluteLogPath(string $path, string $logsDir): string
```

Returns an absolute path for a log file.

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | File path (absolute or relative to $logsDir). |
| `$logsDir` | `string` | Base directory. |

**Returns:** `string`

</details>

<details>
<summary><code>normalizeUserFields()</code></summary>

#### `Dashboard normalizeUserFields()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L368)

```php
private static function normalizeUserFields(array $raw): array
```

Normalises the user_fields config array to a consistent shape. Each field entry is resolved to: label    (string) type     (string, default 'text') max      (int,    default 255) required (bool,   default false) options  (array<string,string>, only for type='select') A plain string value is treated as the label with all defaults applied.

| Param | Type | Description |
|---|---|---|
| `$raw` | `array<string, mixed>` | — |

**Returns:** `array<string, array{label: string, type: string, max: int, required: bool, options: array<string, string>}>`

</details>

<details>
<summary><code>buildContainer()</code></summary>

#### `Dashboard buildContainer()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L394)

```php
private static function buildContainer(string $rootDir, array $config, bool $isDev): Container
```

Builds and populates the DI container.

| Param | Type | Description |
|---|---|---|
| `$rootDir` | `string` | — |
| `$config` | `array<string, mixed>` | — |
| `$isDev` | `bool` | Determines default log level: DEBUG in dev, WARNING in prod. |

**Returns:** `Container`

</details>

<details>
<summary><code>initModuleSchemas()</code></summary>

#### `Dashboard initModuleSchemas()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L875)

```php
private static function initModuleSchemas(ContainerInterface $container, array $config): void
```

Syncs all module schemas against the live database via SchemaStateManager. Processes module schemas from both public and admin registries, plus any top-level 'schema' key defined in $config (tables that do not belong to any module — stored under the pseudo-slug '_bootstrap'). Runs before route registration so all tables are guaranteed to exist on the first request. Uses a hash-based state table (_schema_state) to skip tables whose schema has not changed — no INFORMATION_SCHEMA queries on the happy path. 'schema_defaults.timestamps'/'schema_defaults.soft_deletes' in $config set this app's own default for every table that doesn't declare its own 'timestamps'/'soft_deletes' key — both default to false, unchanged from today's behavior, unless this app's config opts in. Deliberately a separate top-level key from 'schema' (reserved for the top-level standalone-table definitions handled below) to avoid colliding with it.

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | PSR-11 container. |
| `$config` | `array<string, mixed>` | Application config. |

</details>

<details>
<summary><code>runSeeds()</code></summary>

#### `Dashboard runSeeds()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L947)

```php
private static function runSeeds(ContainerInterface $container, array $config): void
```

Runs declarative seed definitions from the 'seed' config key. Seeds are idempotent — each row is skipped when a matching record already exists. Runs after schema sync so all target tables are guaranteed to exist. Uses the raw PDO connection to avoid boot-time interactions with CachingPdo.

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | PSR-11 container. |
| `$config` | `array<string, mixed>` | Application config. |

</details>

<details>
<summary><code>registerRoutes()</code></summary>

#### `Dashboard registerRoutes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L972)

```php
private static function registerRoutes(App $app, ContainerInterface $container, array $config): void
```

Registers all routes: auth, root redirect, module pages and API endpoints. Routes are placed under the dashboard prefix group (config: dashboard.prefix). The inner admin group uses a separate prefix (config: dashboard.admin_prefix). Public-facing routes (modules with auth_required: false) are also inside the dashboard prefix group, consistent with the overall URL structure.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | PSR-11 container. |
| `$config` | `array<string, mixed>` | Resolved application config. |

</details>

<details>
<summary><code>registerErrorHandlers()</code></summary>

#### `Dashboard registerErrorHandlers()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L1168)

```php
private static function registerErrorHandlers(App $app, ContainerInterface $container, bool $isDev): void
```

Registers error handlers: Whoops for dev mode, Twig error templates for production. Template lookup hierarchy (Symfony-style): errors/404.twig → errors/4xx.twig → errors/error.twig All exceptions are logged to the error channel before rendering.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | PSR-11 container. |
| `$isDev` | `bool` | — |

</details>

<details>
<summary><code>addRoutesMap()</code></summary>

#### `Dashboard addRoutesMap()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L1246)

```php
private static function addRoutesMap(mixed $target, array $module, ContainerInterface $container): void
```

Registers multiple named routes for a single module. Use the routes key for modules that need full CRUD (list, new, edit, delete) instead of a single render/handle pair. Route definition format: 'METHOD /path' => callable Handler signature: (ServerRequestInterface, ResponseInterface, array $args, Twig, PDO, ContainerInterface): ResponseInterface The $module array must have 'slug' (used as the route prefix) and 'routes'. Callers decide the target group: admin modules call this on the admin group, module settings call this on the /settings group — the method is group-agnostic. Auth and CSRF are applied at the group level, not per-route.

| Param | Type | Description |
|---|---|---|
| `$target` | `App\|RouteCollectorProxy` | — |
| `$module` | `array<string, mixed>` | — |
| `$container` | `ContainerInterface` | PSR-11 container. |

</details>

<details>
<summary><code>addModuleRoute()</code></summary>

#### `Dashboard addModuleRoute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Dashboard.php#L1275)

```php
private static function addModuleRoute(mixed $target, array $module, ContainerInterface $container): void
```

Registers GET and POST routes for a single module. Module callback signatures (5th arg is PSR-11, optional): render: (ServerRequestInterface, ResponseInterface, Twig, PDO, ContainerInterface): ResponseInterface handle: (ServerRequestInterface, ResponseInterface, Twig, PDO, ContainerInterface): ResponseInterface

| Param | Type | Description |
|---|---|---|
| `$target` | `App\|RouteCollectorProxy` | — |
| `$module` | `array<string, mixed>` | — |
| `$container` | `ContainerInterface` | PSR-11 container. |

</details>

## `ActiveUserExtension`

*final class* — `rafalmasiarek\DashboardKit\Extension\ActiveUserExtension` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/ActiveUserExtension.php#L24)

Blocks login when the user's account has not yet been activated via email. Register only when require_activation is enabled: $auth->addLoginExtension(new ActiveUserExtension()); Implements SchemaProviderInterface so that Auth::createSchema() automatically creates the user_activations table required by the activation token flow.

implements `LoginExtensionInterface`, `SchemaProviderInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`decide()`](#activeuserextension-decide) | public | `LoginDecision` | Deny login when the active flag is falsy on the user record. |
| [`additionalSchema()`](#activeuserextension-additionalschema) | public | `list<string>` | Declares the user_activations table required by the activation token flow. |

<details>
<summary><code>decide()</code></summary>

#### `ActiveUserExtension decide()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/ActiveUserExtension.php#L32)

```php
public function decide(LoginContext $context): LoginDecision
```

Deny login when the active flag is falsy on the user record.

| Param | Type | Description |
|---|---|---|
| `$context` | `LoginContext` | — |

**Returns:** `LoginDecision`

</details>

<details>
<summary><code>additionalSchema()</code></summary>

#### `ActiveUserExtension additionalSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/ActiveUserExtension.php#L49)

```php
public function additionalSchema(string $driver): array
```

Declares the user_activations table required by the activation token flow.

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name. |

**Returns:** `list<string>`

</details>

## `FormSlotRegistry`

*final class* — `rafalmasiarek\DashboardKit\Extension\FormSlotRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/FormSlotRegistry.php#L25)

Registry for plugin-provided HTML snippets injected into auth forms. Plugins call register() during their addon registration. Each slot is identified by a form name ('login', 'register') and a slot name ('form_fields', 'scripts'). Slots are rendered via the form_slot() Twig function. Content can be a plain HTML string or a callable that returns a string. Callables are evaluated at render time, giving access to PHP runtime state such as $_SESSION, which is useful for conditional injection (e.g. show reCAPTCHA only after a threshold of failed login attempts). Built-in slot names used by dashboard-kit templates: form_fields — injected inside the <form> element, before the submit button. scripts     — injected in the page's scripts block, outside the <form>.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$slots` | `array<string, list<array{content: string\|callable(): string, order: int}>>` | private | Registered slot entries keyed by "{form}.{slot}". |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#formslotregistry-register) | public | `void` | Register an HTML snippet or a callable for a specific form slot. |
| [`render()`](#formslotregistry-render) | public | `string` | Render all registered snippets for the given form and slot. Entries are sorted by order before concatenation. Callable entries are invoked at render time so they can inspect session or request state. |

<details>
<summary><code>register()</code></summary>

#### `FormSlotRegistry register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/FormSlotRegistry.php#L44)

```php
public function register(string $form, string $slot, string|callable $content, int $order): void
```

Register an HTML snippet or a callable for a specific form slot.

| Param | Type | Description |
|---|---|---|
| `$form` | `string` | Form name, e.g. 'login' or 'register'. |
| `$slot` | `string` | Slot name, e.g. 'form_fields' or 'scripts'. |
| `$content` | `string\|callable` | HTML string or callable returning an HTML string. Callable signature: (): string |
| `$order` | `int` | Sort order. Lower numbers are rendered first. |

</details>

<details>
<summary><code>render()</code></summary>

#### `FormSlotRegistry render()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/FormSlotRegistry.php#L59)

```php
public function render(string $form, string $slot): string
```

Render all registered snippets for the given form and slot. Entries are sorted by order before concatenation. Callable entries are invoked at render time so they can inspect session or request state.

| Param | Type | Description |
|---|---|---|
| `$form` | `string` | Form name, e.g. 'login'. |
| `$slot` | `string` | Slot name, e.g. 'form_fields'. |

**Returns:** `string` — Concatenated HTML, safe to output raw.

</details>

## `SettingsSectionRegistry`

*final class* — `rafalmasiarek\DashboardKit\Extension\SettingsSectionRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/SettingsSectionRegistry.php#L21)

Registry for plugin-provided settings sections. Plugins call register() during their addon registration. The settings template iterates all() to render a navigation list at the bottom of the built-in settings page, linking each section to its own URL. Section definition keys: title  (string) Display name shown in the settings navigation. path   (string) Absolute URL path, e.g. '/settings/api-tokens'. order  (int)    Sort order. Lower numbers appear first. Default: 100.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$sections` | `array<string, array{title: string, path: string, order: int}>` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#settingssectionregistry-register) | public | `void` | Register a settings section. |
| [`all()`](#settingssectionregistry-all) | public | `array<string, array{slug: string, title: string, path: string, order: int}>` | Return all registered sections sorted by order. |

<details>
<summary><code>register()</code></summary>

#### `SettingsSectionRegistry register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/SettingsSectionRegistry.php#L33)

```php
public function register(string $slug, array $definition): void
```

Register a settings section.

| Param | Type | Description |
|---|---|---|
| `$slug` | `string` | Unique identifier for this section. |
| `$definition` | `array<string, mixed>` | Section definition (title, path, order). |

</details>

<details>
<summary><code>all()</code></summary>

#### `SettingsSectionRegistry all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/SettingsSectionRegistry.php#L48)

```php
public function all(): array
```

Return all registered sections sorted by order.

**Returns:** `array<string, array{slug: string, title: string, path: string, order: int}>`

</details>

## `SuspendedUserExtension`

*final class* — `rafalmasiarek\DashboardKit\Extension\SuspendedUserExtension` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/SuspendedUserExtension.php#L24)

Blocks login when the user's account has been suspended by an admin. Register unconditionally via Auth::addLoginExtension(): $auth->addLoginExtension(new SuspendedUserExtension()); Implements SchemaProviderInterface so that Auth::createSchema() automatically adds the suspended_at column to the users table.

implements `LoginExtensionInterface`, `SchemaProviderInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`decide()`](#suspendeduserextension-decide) | public | `LoginDecision` | Deny login when suspended_at is set on the user record. |
| [`additionalSchema()`](#suspendeduserextension-additionalschema) | public | `list<string>` | Declares the suspended_at column required by this extension. |

<details>
<summary><code>decide()</code></summary>

#### `SuspendedUserExtension decide()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/SuspendedUserExtension.php#L32)

```php
public function decide(LoginContext $context): LoginDecision
```

Deny login when suspended_at is set on the user record.

| Param | Type | Description |
|---|---|---|
| `$context` | `LoginContext` | — |

**Returns:** `LoginDecision`

</details>

<details>
<summary><code>additionalSchema()</code></summary>

#### `SuspendedUserExtension additionalSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/SuspendedUserExtension.php#L47)

```php
public function additionalSchema(string $driver): array
```

Declares the suspended_at column required by this extension.

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name. |

**Returns:** `list<string>`

</details>

## `UserActionRegistry`

*final class* — `rafalmasiarek\DashboardKit\Extension\UserActionRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/UserActionRegistry.php#L21)

Registry for plugin-provided user action buttons in the admin users list. Plugins call register() during their addon registration. The admin users list template iterates all() and renders an additional button per user row, constructing the URL from path_pattern by replacing {id} with the user ID. Action definition keys: label        (string) Button label, e.g. 'API Scopes'. path_pattern (string) URL pattern with {id} placeholder, e.g. '/admin/api/users/{id}/scopes'. order        (int)    Sort order for button positioning. Default: 100.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$actions` | `array<string, array{slug: string, label: string, path_pattern: string, order: int}>` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#useractionregistry-register) | public | `void` | Register a user action. |
| [`all()`](#useractionregistry-all) | public | `array<string, array{slug: string, label: string, path_pattern: string, order: int}>` | Return all registered actions sorted by order. |
| [`urlFor()`](#useractionregistry-urlfor) | public | `string\|null` | Resolve a URL for a specific user ID by replacing the {id} placeholder. |

<details>
<summary><code>register()</code></summary>

#### `UserActionRegistry register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/UserActionRegistry.php#L33)

```php
public function register(string $slug, array $definition): void
```

Register a user action.

| Param | Type | Description |
|---|---|---|
| `$slug` | `string` | Unique identifier for this action. |
| `$definition` | `array<string, mixed>` | Action definition (label, path_pattern, order). |

</details>

<details>
<summary><code>all()</code></summary>

#### `UserActionRegistry all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/UserActionRegistry.php#L48)

```php
public function all(): array
```

Return all registered actions sorted by order.

**Returns:** `array<string, array{slug: string, label: string, path_pattern: string, order: int}>`

</details>

<details>
<summary><code>urlFor()</code></summary>

#### `UserActionRegistry urlFor()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Extension/UserActionRegistry.php#L62)

```php
public function urlFor(string $slug, string $userId): ?string
```

Resolve a URL for a specific user ID by replacing the {id} placeholder.

| Param | Type | Description |
|---|---|---|
| `$slug` | `string` | Action slug. |
| `$userId` | `string` | User ID to substitute. |

**Returns:** `string\|null` — Resolved URL, or null if the action is not registered.

</details>

## `Flash`

*class* — `rafalmasiarek\DashboardKit\Flash` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Flash.php#L13)

Session-based flash message store. Messages are written to the session and consumed exactly once on the next request. Reading via get() clears the store.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$loaded` | `array<string, list<string>>\|null` | private | Lazily loaded from session. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`add()`](#flash-add) | public | `void` | Adds a flash message of the given type. |
| [`get()`](#flash-get) | public | `array<string, list<string>>` | Reads and clears all flash messages for the current request. |

<details>
<summary><code>add()</code></summary>

#### `Flash add()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Flash.php#L24)

```php
public function add(string $type, string $message): void
```

Adds a flash message of the given type.

| Param | Type | Description |
|---|---|---|
| `$type` | `string` | Bootstrap alert type (success, danger, warning, info). |
| `$message` | `string` | Human-readable message text. |

</details>

<details>
<summary><code>get()</code></summary>

#### `Flash get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Flash.php#L37)

```php
public function get(): array
```

Reads and clears all flash messages for the current request.

**Returns:** `array<string, list<string>>` — Keyed by alert type.

</details>

## `FlashAuthHook`

*final class* — `rafalmasiarek\DashboardKit\Hook\FlashAuthHook` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/FlashAuthHook.php#L20)

Bridges AuthKit's low-level lifecycle hooks to dashboard-kit's flash messages. Only overrides hooks with no HookRegistry equivalent. onLogoutExpired fires from deep inside Auth::getUser() when a session token existed but is no longer valid (expired or revoked) — a case no controller action ever sees, so HookRegistry (which controllers emit into) can never cover it.

extends `AbstractHook`

**Depends on:** [`Flash`](#flash)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#flashauthhook-__construct) | public | — | — |
| [`onLogoutExpired()`](#flashauthhook-onlogoutexpired) | public | `void` | {@inheritDoc} |

<details>
<summary><code>__construct()</code></summary>

#### `FlashAuthHook __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/FlashAuthHook.php#L25)

```php
public function __construct(Flash $flash)
```

| Param | Type | Description |
|---|---|---|
| `$flash` | [`Flash`](#flash) | — |

</details>

<details>
<summary><code>onLogoutExpired()</code></summary>

#### `FlashAuthHook onLogoutExpired()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/FlashAuthHook.php#L32)

```php
public function onLogoutExpired(): void
```

{@inheritDoc}

</details>

## `HookRegistry`

*class* — `rafalmasiarek\DashboardKit\Hook\HookRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/HookRegistry.php#L42)

Lightweight event hook registry. Each event holds exactly one on()-registered listener — calling on() replaces any previous registration for that event. This is intentional: it allows application code to override the default flash-message hooks registered by the dashboard without ending up with two flashes firing simultaneously. addListener() is a separate, additive mechanism for events meant to host multiple independent plugins (e.g. mail_sending): every registered listener runs, in registration order, and a listener that throws is caught and logged — it never blocks the other listeners or whatever called emit(). Supported events and their argument signatures: register                  (User $user) register_failed           (string $email, string $message) login                     (User $user) login_failed              (string $email) logout                    (User $user) password_reset_requested  (string $email) password_reset_completed  (User $user) password_changed          (User $user) email_changed             (User $user, string $oldEmail) profile_updated           (User $user, array $updatedFields) user_created              (User $target, User $admin) user_suspended            (User $target, User $admin) user_unsuspended          (User $target, User $admin) user_updated              (User $target, string[] $changedFields, User $admin) user_deleted              (User $target, User $admin) mail_sending               (MailMessage $message) — addListener() only, before delivery mail_sent                 (MailMessage $message) mail_failed               (MailMessage $message, \Throwable $e)

**Depends on:** `LoggerInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$listeners` | `array<string, callable>` | private | — |
| `$pluginListeners` | `array<string, list<callable>>` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#hookregistry-__construct) | public | — | — |
| [`on()`](#hookregistry-on) | public | `self` | Registers a listener for the given event, replacing any previous one. |
| [`addListener()`](#hookregistry-addlistener) | public | `self` | Registers an additional, isolated listener for the given event. Unlike on(), multiple callers can each addListener() for the same event without overwriting one another. A listener that throws is caught and logged by emit() — it never blocks the other listeners for this event, nor whatever called emit() in the first place. Intended for plugin-style integrations (e.g. mail_sending) where an addon must never be able to break the core flow it's observing. |
| [`emit()`](#hookregistry-emit) | public | `void` | Calls the listener registered for the given event via on(), if any, then every listener registered via addListener(), in registration order. Exceptions thrown by the on() listener propagate to the caller, as before. Exceptions thrown by an addListener() listener are caught and logged — they never propagate. |

<details>
<summary><code>__construct()</code></summary>

#### `HookRegistry __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/HookRegistry.php#L50)

```php
public function __construct(?LoggerInterface $logger)
```

| Param | Type | Description |
|---|---|---|
| `$logger` | `?LoggerInterface` | — |

</details>

<details>
<summary><code>on()</code></summary>

#### `HookRegistry on()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/HookRegistry.php#L62)

```php
public function on(string $event, callable $listener): self
```

Registers a listener for the given event, replacing any previous one.

| Param | Type | Description |
|---|---|---|
| `$event` | `string` | Event name (see class docblock for supported events). |
| `$listener` | `callable` | Callback invoked with event-specific arguments. |

**Returns:** `self`

</details>

<details>
<summary><code>addListener()</code></summary>

#### `HookRegistry addListener()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/HookRegistry.php#L82)

```php
public function addListener(string $event, callable $listener): self
```

Registers an additional, isolated listener for the given event. Unlike on(), multiple callers can each addListener() for the same event without overwriting one another. A listener that throws is caught and logged by emit() — it never blocks the other listeners for this event, nor whatever called emit() in the first place. Intended for plugin-style integrations (e.g. mail_sending) where an addon must never be able to break the core flow it's observing.

| Param | Type | Description |
|---|---|---|
| `$event` | `string` | Event name (see class docblock for supported events). |
| `$listener` | `callable` | Callback invoked with event-specific arguments. |

**Returns:** `self`

</details>

<details>
<summary><code>emit()</code></summary>

#### `HookRegistry emit()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Hook/HookRegistry.php#L99)

```php
public function emit(string $event, mixed $args): void
```

Calls the listener registered for the given event via on(), if any, then every listener registered via addListener(), in registration order. Exceptions thrown by the on() listener propagate to the caller, as before. Exceptions thrown by an addListener() listener are caught and logged — they never propagate.

| Param | Type | Description |
|---|---|---|
| `$event` | `string` | Event name. |
| `$args` | `mixed` | — |

</details>

## `HttpMessagePicker`

*final class* — `rafalmasiarek\DashboardKit\Http\HttpMessagePicker` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/HttpMessagePicker.php#L19)

Converts a symbolic domain error code into an HTTP status + human-readable message pair. Works with any resolver object that exposes: describe(string $code): array{message: string, http?: int} Fallback rules: HTTP:    resolver['http'] → 200 (if $ok and code starts with "OK_") → 400 Message: $fallbackMessage → resolver['message'] → $code itself

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`pick()`](#httpmessagepicker-pick) | public static | `array{http: int, message: string}` | Resolve both the HTTP status and the human-readable message at once. |
| [`pickHttp()`](#httpmessagepicker-pickhttp) | public static | `int` | Resolve only the HTTP status code. |
| [`pickMessage()`](#httpmessagepicker-pickmessage) | public static | `string` | Resolve only the human-readable message. |
| [`describe()`](#httpmessagepicker-describe) | private static | `array{message: string, http?: int}` | Duck-typed describe() call with a safe fallback. |

<details>
<summary><code>pick()</code></summary>

#### `HttpMessagePicker pick()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/HttpMessagePicker.php#L30)

```php
public static function pick(object $resolver, string $code, bool $ok, string $fallbackMessage): array
```

Resolve both the HTTP status and the human-readable message at once.

| Param | Type | Description |
|---|---|---|
| `$resolver` | `object` | Any object with a describe(string): array method. |
| `$code` | `string` | Symbolic domain code (e.g. "OK_SENT", "EMAIL_INVALID"). |
| `$ok` | `bool` | True when the underlying operation succeeded. |
| `$fallbackMessage` | `string` | Optional message provided by the caller; takes precedence over the descriptor. |

**Returns:** `array{http: int, message: string}`

</details>

<details>
<summary><code>pickHttp()</code></summary>

#### `HttpMessagePicker pickHttp()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/HttpMessagePicker.php#L53)

```php
public static function pickHttp(object $resolver, string $code, bool $ok): int
```

Resolve only the HTTP status code.

| Param | Type | Description |
|---|---|---|
| `$resolver` | `object` | — |
| `$code` | `string` | — |
| `$ok` | `bool` | — |

**Returns:** `int`

</details>

<details>
<summary><code>pickMessage()</code></summary>

#### `HttpMessagePicker pickMessage()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/HttpMessagePicker.php#L67)

```php
public static function pickMessage(object $resolver, string $code, bool $ok, string $fallbackMessage): string
```

Resolve only the human-readable message.

| Param | Type | Description |
|---|---|---|
| `$resolver` | `object` | — |
| `$code` | `string` | — |
| `$ok` | `bool` | — |
| `$fallbackMessage` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>describe()</code></summary>

#### `HttpMessagePicker describe()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/HttpMessagePicker.php#L79)

```php
private static function describe(object $resolver, string $code): array
```

Duck-typed describe() call with a safe fallback.

| Param | Type | Description |
|---|---|---|
| `$resolver` | `object` | — |
| `$code` | `string` | — |

**Returns:** `array{message: string, http?: int}`

</details>

## `RequestTimingMiddleware`

*final class* — `rafalmasiarek\DashboardKit\Http\RequestTimingMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/RequestTimingMiddleware.php#L36)

PSR-15 middleware that writes one access log entry per request. Uses $_SERVER['REQUEST_TIME_FLOAT'] as the start timestamp — set by nginx/Apache when the connection is accepted, before PHP starts executing. The measured duration therefore includes PHP startup, autoloading, container setup, and the full stack. Logged fields: method, path, status, duration_ms — always present query.*                           — GET parameters (sanitized) body.*                            — POST/form parameters (sanitized) referer                           — HTTP Referer header when present Sensitive fields (password, token, …) are masked as "***". Identity fields (email, login, …) are AES-256-CBC encrypted with APP_KEY. Log output example: [level=info] [channel=app] access method=POST path=/login status=302 duration_ms=45.2 body.email=enc:abc123 body.password=*** referer=https://example.com req.ip=1.2.3.4 req.id=…

implements `MiddlewareInterface`

**Depends on:** `LoggerInterface`, [`RequestLogSanitizer`](#requestlogsanitizer)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#requesttimingmiddleware-__construct) | public | — | — |
| [`process()`](#requesttimingmiddleware-process) | public | `ResponseInterface` | Handles the request and logs one access entry after the response is produced. |

<details>
<summary><code>__construct()</code></summary>

#### `RequestTimingMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/RequestTimingMiddleware.php#L42)

```php
public function __construct(LoggerInterface $logger, RequestLogSanitizer $sanitizer)
```

| Param | Type | Description |
|---|---|---|
| `$logger` | `LoggerInterface` | Logger to write access entries to. |
| `$sanitizer` | [`RequestLogSanitizer`](#requestlogsanitizer) | Sanitizes query/body params before logging. |

</details>

<details>
<summary><code>process()</code></summary>

#### `RequestTimingMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/RequestTimingMiddleware.php#L55)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

Handles the request and logs one access entry after the response is produced.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$handler` | `RequestHandlerInterface` | — |

**Returns:** `ResponseInterface`

</details>

## `SafeErrorHandler`

*final class* — `rafalmasiarek\DashboardKit\Http\SafeErrorHandler` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L30)

Slim 4 default error handler with content negotiation and secret redaction. - Returns a JSON envelope for API/CLI/AJAX requests (Accept: application/json, Content-Type: application/json, X-Requested-With: XMLHttpRequest, ?format=json, or CLI SAPI). - Delegates HTML rendering to an optional callable for browser requests. - Falls back to plain text when no HTML renderer is provided or it returns null. - Redacts common secret patterns (tokens, passwords, API keys) from messages and traces. - Shortens absolute file paths when displayErrorDetails is false (production). JSON envelope shape (always present, mirrors JsonHandler for consistency): { "status": "error", "code": <int>, "message": <string>, "data": {}, "errors": [] }

**Depends on:** `ResponseFactoryInterface`, `LoggerInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$responseFactory` | `ResponseFactoryInterface` | private | PSR-7 response factory. |
| `$logger` | `LoggerInterface\|null` | private | Optional PSR-3 logger for error entries. |
| `$htmlRenderer` | `callable\|null` | private | Optional HTML renderer callback. Signature: fn(int $status, string $message, bool $showDetails, ServerRequestInterface $request): ?ResponseInterface Return null to fall through to plain-text fallback. |
| `$whoops` | `object\|null` | private | Optional Whoops instance used as the HTML renderer in dev mode. When set, browser requests bypass $htmlRenderer and are served by Whoops. Typed as object to avoid a hard dependency on filp/whoops at the class level (it is a require-dev package — may not be installed in production). |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#safeerrorhandler-__construct) | public | — | — |
| [`__invoke()`](#safeerrorhandler-__invoke) | public | `ResponseInterface` | Invokable Slim default error handler. |
| [`statusFromException()`](#safeerrorhandler-statusfromexception) | private | `int` | Map an exception to an HTTP status code. |
| [`wantsJson()`](#safeerrorhandler-wantsjson) | private | `bool` | Heuristics: return true when the client expects a JSON response. |
| [`wantsHtml()`](#safeerrorhandler-wantshtml) | private | `bool` | Heuristics: return true when the client expects an HTML response. |
| [`safeTrace()`](#safeerrorhandler-safetrace) | private | `string[]` | Build a redacted stack trace; optionally shorten absolute paths. |
| [`safeMessage()`](#safeerrorhandler-safemessage) | private | `string` | Redact known secret patterns from a string. Covers: ENV-style KEY=VALUE pairs, common query/body param names (token, password, api_key, secret), and Basic-Auth credentials in URIs. Optionally shortens absolute filesystem paths. |
| [`shortenPath()`](#safeerrorhandler-shortenpath) | private | `string` | Collapse absolute filesystem paths to ".../filename.ext". |

<details>
<summary><code>__construct()</code></summary>

#### `SafeErrorHandler __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L73)

```php
public function __construct(ResponseFactoryInterface $responseFactory, ?LoggerInterface $logger, ?callable $htmlRenderer, ?object $whoops)
```

| Param | Type | Description |
|---|---|---|
| `$responseFactory` | `ResponseFactoryInterface` | — |
| `$logger` | `LoggerInterface\|null` | Optional PSR-3 logger; errors are logged at error/warning level. |
| `$htmlRenderer` | `callable\|null` | Optional HTML renderer for browser requests (Twig templates). |
| `$whoops` | `object\|null` | Optional \Whoops\Run instance; when set, serves HTML via Whoops instead of $htmlRenderer. |

</details>

<details>
<summary><code>__invoke()</code></summary>

#### `SafeErrorHandler __invoke()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L95)

```php
public function __invoke(ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails): ResponseInterface
```

Invokable Slim default error handler.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$exception` | `Throwable` | — |
| `$displayErrorDetails` | `bool` | Whether to include stack traces. |
| `$logErrors` | `bool` | Whether to log the error. |
| `$logErrorDetails` | `bool` | Whether to include the exception in log context. |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>statusFromException()</code></summary>

#### `SafeErrorHandler statusFromException()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L165)

```php
private function statusFromException(Throwable $e): int
```

Map an exception to an HTTP status code.

| Param | Type | Description |
|---|---|---|
| `$e` | `Throwable` | — |

**Returns:** `int`

</details>

<details>
<summary><code>wantsJson()</code></summary>

#### `SafeErrorHandler wantsJson()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L180)

```php
private function wantsJson(ServerRequestInterface $request): bool
```

Heuristics: return true when the client expects a JSON response.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>wantsHtml()</code></summary>

#### `SafeErrorHandler wantsHtml()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L204)

```php
private function wantsHtml(ServerRequestInterface $request): bool
```

Heuristics: return true when the client expects an HTML response.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>safeTrace()</code></summary>

#### `SafeErrorHandler safeTrace()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L220)

```php
private function safeTrace(Throwable $e, bool $shortenPaths): array
```

Build a redacted stack trace; optionally shorten absolute paths.

| Param | Type | Description |
|---|---|---|
| `$e` | `Throwable` | — |
| `$shortenPaths` | `bool` | — |

**Returns:** `string[]`

</details>

<details>
<summary><code>safeMessage()</code></summary>

#### `SafeErrorHandler safeMessage()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L242)

```php
private function safeMessage(string $msg, bool $shortenPaths): string
```

Redact known secret patterns from a string. Covers: ENV-style KEY=VALUE pairs, common query/body param names (token, password, api_key, secret), and Basic-Auth credentials in URIs. Optionally shortens absolute filesystem paths.

| Param | Type | Description |
|---|---|---|
| `$msg` | `string` | — |
| `$shortenPaths` | `bool` | — |

**Returns:** `string`

</details>

<details>
<summary><code>shortenPath()</code></summary>

#### `SafeErrorHandler shortenPath()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Http/SafeErrorHandler.php#L279)

```php
private function shortenPath(string $s): string
```

Collapse absolute filesystem paths to ".../filename.ext".

| Param | Type | Description |
|---|---|---|
| `$s` | `string` | — |

**Returns:** `string`

</details>

## `AuditLog`

*class* — `rafalmasiarek\DashboardKit\Log\AuditLog` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L21)

Writes structured audit entries for dashboard user and admin actions. Each method logs at INFO level with a consistent context shape: user_id, email — always present admin_id, admin_email — present for admin-initiated actions extra payload (old_email, fields, old_role, new_role) — action-specific The client IP is injected automatically into every log record by RequestHeadersProcessor (req.ip) and does not need to be passed explicitly.

**Depends on:** `LoggerInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#auditlog-__construct) | public | — | — |
| [`register()`](#auditlog-register) | public | `void` | Logs a successful user registration. |
| [`login()`](#auditlog-login) | public | `void` | Logs a successful login. |
| [`logout()`](#auditlog-logout) | public | `void` | Logs a logout. |
| [`passwordChanged()`](#auditlog-passwordchanged) | public | `void` | Logs a password change initiated by the user themselves. |
| [`emailChanged()`](#auditlog-emailchanged) | public | `void` | Logs an email address change. |
| [`profileUpdated()`](#auditlog-profileupdated) | public | `void` | Logs a profile fields update. |
| [`userSuspended()`](#auditlog-usersuspended) | public | `void` | Logs an admin suspending a user account. |
| [`userUnsuspended()`](#auditlog-userunsuspended) | public | `void` | Logs an admin unsuspending a user account. |
| [`roleChanged()`](#auditlog-rolechanged) | public | `void` | Logs an admin changing a user's role. |
| [`userUpdated()`](#auditlog-userupdated) | public | `void` | Logs an admin editing a user's account fields. |
| [`userDeleted()`](#auditlog-userdeleted) | public | `void` | Logs an admin permanently deleting a user account. |
| [`userCreated()`](#auditlog-usercreated) | public | `void` | Logs an admin manually creating a new user account. |
| [`activationAttempt()`](#auditlog-activationattempt) | public | `void` | Logs an account activation attempt with its outcome. |
| [`mailAttempt()`](#auditlog-mailattempt) | public | `void` | Logs a mail delivery attempt with its outcome. Logs at INFO on success, ERROR on failure — same event name in both cases, so a single grep shows the full picture: sent + failed side by side. |
| [`mailOpen()`](#auditlog-mailopen) | public | `void` | Logs a tracking-pixel open event — the recipient opened the email. |
| [`passwordResetRequested()`](#auditlog-passwordresetrequested) | public | `void` | Logs a password reset request (token generated and email dispatched). |
| [`passwordResetCompleted()`](#auditlog-passwordresetcompleted) | public | `void` | Logs a successfully completed password reset. |
| [`loginFailed()`](#auditlog-loginfailed) | public | `void` | Logs a failed login attempt. |
| [`registerFailed()`](#auditlog-registerfailed) | public | `void` | Logs a failed registration attempt. |
| [`passwordChangeFailed()`](#auditlog-passwordchangefailed) | public | `void` | Logs a failed password change attempt. |
| [`passwordResetFailed()`](#auditlog-passwordresetfailed) | public | `void` | Logs a failed password reset completion (invalid or expired token). |
| [`ctx()`](#auditlog-ctx) | private | `array<string, mixed>` | Builds the base context array for user-initiated actions. |
| [`adminCtx()`](#auditlog-adminctx) | private | `array<string, mixed>` | Builds the context array for admin-initiated actions targeting another user. |

<details>
<summary><code>__construct()</code></summary>

#### `AuditLog __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L26)

```php
public function __construct(LoggerInterface $logger)
```

| Param | Type | Description |
|---|---|---|
| `$logger` | `LoggerInterface` | PSR-3 logger receiving audit entries. |

</details>

<details>
<summary><code>register()</code></summary>

#### `AuditLog register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L35)

```php
public function register(User $user): void
```

Logs a successful user registration.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | Newly registered user. |

</details>

<details>
<summary><code>login()</code></summary>

#### `AuditLog login()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L45)

```php
public function login(User $user): void
```

Logs a successful login.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | Authenticated user. |

</details>

<details>
<summary><code>logout()</code></summary>

#### `AuditLog logout()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L55)

```php
public function logout(User $user): void
```

Logs a logout.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | User who logged out. |

</details>

<details>
<summary><code>passwordChanged()</code></summary>

#### `AuditLog passwordChanged()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L65)

```php
public function passwordChanged(User $user): void
```

Logs a password change initiated by the user themselves.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | User who changed their password. |

</details>

<details>
<summary><code>emailChanged()</code></summary>

#### `AuditLog emailChanged()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L76)

```php
public function emailChanged(User $user, string $oldEmail): void
```

Logs an email address change.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | User whose email changed. |
| `$oldEmail` | `string` | Previous email address. |

</details>

<details>
<summary><code>profileUpdated()</code></summary>

#### `AuditLog profileUpdated()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L89)

```php
public function profileUpdated(User $user, array $fields): void
```

Logs a profile fields update.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | User whose profile was updated. |
| `$fields` | `array<string, mixed>` | Map of field names that were updated. |

</details>

<details>
<summary><code>userSuspended()</code></summary>

#### `AuditLog userSuspended()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L102)

```php
public function userSuspended(User $target, User $admin): void
```

Logs an admin suspending a user account.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | Suspended user. |
| `$admin` | `User` | Admin who performed the action. |

</details>

<details>
<summary><code>userUnsuspended()</code></summary>

#### `AuditLog userUnsuspended()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L113)

```php
public function userUnsuspended(User $target, User $admin): void
```

Logs an admin unsuspending a user account.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | Unsuspended user. |
| `$admin` | `User` | Admin who performed the action. |

</details>

<details>
<summary><code>roleChanged()</code></summary>

#### `AuditLog roleChanged()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L126)

```php
public function roleChanged(User $target, string $oldRole, string $newRole, User $admin): void
```

Logs an admin changing a user's role.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | User whose role changed. |
| `$oldRole` | `string` | Previous role value. |
| `$newRole` | `string` | New role value. |
| `$admin` | `User` | Admin who performed the action. |

</details>

<details>
<summary><code>userUpdated()</code></summary>

#### `AuditLog userUpdated()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L141)

```php
public function userUpdated(User $target, array $changedFields, User $admin): void
```

Logs an admin editing a user's account fields.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | User whose account was updated. |
| `$changedFields` | `array<int, string>` | Names of fields that were modified. |
| `$admin` | `User` | Admin who performed the action. |

</details>

<details>
<summary><code>userDeleted()</code></summary>

#### `AuditLog userDeleted()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L154)

```php
public function userDeleted(User $target, User $admin): void
```

Logs an admin permanently deleting a user account.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | User who was deleted. |
| `$admin` | `User` | Admin who performed the action. |

</details>

<details>
<summary><code>userCreated()</code></summary>

#### `AuditLog userCreated()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L165)

```php
public function userCreated(User $target, User $admin): void
```

Logs an admin manually creating a new user account.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | Created user. |
| `$admin` | `User` | Admin who performed the action. |

</details>

<details>
<summary><code>activationAttempt()</code></summary>

#### `AuditLog activationAttempt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L177)

```php
public function activationAttempt(bool $success, ?int $userId, ?string $email): void
```

Logs an account activation attempt with its outcome.

| Param | Type | Description |
|---|---|---|
| `$success` | `bool` | Whether the token was valid and the account was activated. |
| `$userId` | `int\|null` | Resolved user ID (present on success only). |
| `$email` | `string\|null` | Resolved user email (present on success only). |

</details>

<details>
<summary><code>mailAttempt()</code></summary>

#### `AuditLog mailAttempt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L205)

```php
public function mailAttempt(bool $success, string $to, string $subject, ?string $error, ?string $id): void
```

Logs a mail delivery attempt with its outcome. Logs at INFO on success, ERROR on failure — same event name in both cases, so a single grep shows the full picture: sent + failed side by side.

| Param | Type | Description |
|---|---|---|
| `$success` | `bool` | Whether the driver accepted the message without throwing. |
| `$to` | `string` | Recipient email address. |
| `$subject` | `string` | Email subject line. |
| `$error` | `string\|null` | Driver error message (present on failure only). |
| `$id` | `string\|null` | Caller-supplied correlation id (MailMessage::getCorrelationId()), when set. |

</details>

<details>
<summary><code>mailOpen()</code></summary>

#### `AuditLog mailOpen()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L226)

```php
public function mailOpen(string $to, string $mailType, ?string $id): void
```

Logs a tracking-pixel open event — the recipient opened the email.

| Param | Type | Description |
|---|---|---|
| `$to` | `string` | Recipient email address. |
| `$mailType` | `string` | Application-defined mail type (e.g. 'activation', 'password-reset'). |
| `$id` | `string\|null` | Caller-supplied correlation id carried over from the original send, when set. |

</details>

<details>
<summary><code>passwordResetRequested()</code></summary>

#### `AuditLog passwordResetRequested()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L240)

```php
public function passwordResetRequested(string $email): void
```

Logs a password reset request (token generated and email dispatched).

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | Email address for which the reset was requested. |

</details>

<details>
<summary><code>passwordResetCompleted()</code></summary>

#### `AuditLog passwordResetCompleted()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L250)

```php
public function passwordResetCompleted(User $user): void
```

Logs a successfully completed password reset.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | User who completed the reset. |

</details>

<details>
<summary><code>loginFailed()</code></summary>

#### `AuditLog loginFailed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L261)

```php
public function loginFailed(string $email, string $reason): void
```

Logs a failed login attempt.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | Email address used in the attempt. |
| `$reason` | `string` | Error message returned by AuthKit. |

</details>

<details>
<summary><code>registerFailed()</code></summary>

#### `AuditLog registerFailed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L272)

```php
public function registerFailed(string $email, string $reason): void
```

Logs a failed registration attempt.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | Email address used in the attempt. |
| `$reason` | `string` | Error message returned by AuthKit. |

</details>

<details>
<summary><code>passwordChangeFailed()</code></summary>

#### `AuditLog passwordChangeFailed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L283)

```php
public function passwordChangeFailed(User $user, string $reason): void
```

Logs a failed password change attempt.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | User who attempted the change. |
| `$reason` | `string` | Description of why the change was rejected. |

</details>

<details>
<summary><code>passwordResetFailed()</code></summary>

#### `AuditLog passwordResetFailed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L293)

```php
public function passwordResetFailed(?string $email): void
```

Logs a failed password reset completion (invalid or expired token).

| Param | Type | Description |
|---|---|---|
| `$email` | `string\|null` | Email address associated with the reset attempt, if known. |

</details>

<details>
<summary><code>ctx()</code></summary>

#### `AuditLog ctx()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L304)

```php
private function ctx(User $user): array
```

Builds the base context array for user-initiated actions.

| Param | Type | Description |
|---|---|---|
| `$user` | `User` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>adminCtx()</code></summary>

#### `AuditLog adminCtx()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/AuditLog.php#L319)

```php
private function adminCtx(User $target, User $admin): array
```

Builds the context array for admin-initiated actions targeting another user.

| Param | Type | Description |
|---|---|---|
| `$target` | `User` | — |
| `$admin` | `User` | — |

**Returns:** `array<string, mixed>`

</details>

## `KvLineFormatter`

*class* — `rafalmasiarek\DashboardKit\Log\KvLineFormatter` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/KvLineFormatter.php#L19)

Formats a Monolog record as a key-value line compatible with log_ship.sh's `kv` parser. Output format: [YYYY-MM-DD HH:MM:SS] [level=info] [channel=audit] event_name key=val key2=val2 Configure log_ship.conf PARSERS entry: "kv | level=level | msg=message"

implements `FormatterInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`format()`](#kvlineformatter-format) | public | `string` | Formats a single log record into a kv line. |
| [`formatBatch()`](#kvlineformatter-formatbatch) | public | `string` | Formats a batch of log records. |
| [`escapeValue()`](#kvlineformatter-escapevalue) | private | `string` | Escapes a value for safe kv output. Strings with spaces or quotes are double-quoted. Arrays and objects are JSON-encoded. |

<details>
<summary><code>format()</code></summary>

#### `KvLineFormatter format()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/KvLineFormatter.php#L27)

```php
public function format(LogRecord $record): string
```

Formats a single log record into a kv line.

| Param | Type | Description |
|---|---|---|
| `$record` | `LogRecord` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatBatch()</code></summary>

#### `KvLineFormatter formatBatch()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/KvLineFormatter.php#L57)

```php
public function formatBatch(array $records): string
```

Formats a batch of log records.

| Param | Type | Description |
|---|---|---|
| `$records` | `LogRecord[]` | — |

**Returns:** `string`

</details>

<details>
<summary><code>escapeValue()</code></summary>

#### `KvLineFormatter escapeValue()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/KvLineFormatter.php#L71)

```php
private function escapeValue(mixed $value): string
```

Escapes a value for safe kv output. Strings with spaces or quotes are double-quoted. Arrays and objects are JSON-encoded.

| Param | Type | Description |
|---|---|---|
| `$value` | `mixed` | — |

**Returns:** `string`

</details>

## `LoggerFactory`

*class* — `rafalmasiarek\DashboardKit\Log\LoggerFactory` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/LoggerFactory.php#L19)

Builds Monolog channel loggers with KvLineFormatter. Log format is compatible with log_ship.sh's `kv` parser. Recommended PARSERS entry in log_ship.conf: "kv | level=level | msg=message"

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`create()`](#loggerfactory-create) | public static | `Logger` | Creates a channel logger from a resolved channel config. When $cascadeErrorPath is provided a second ERROR+ handler is added to that file, allowing a channel to write high-severity events to a secondary log file as well. |
| [`toMonologLevel()`](#loggerfactory-tomonologlevel) | public static | `Level` | Maps a PSR-3 level string to a Monolog Level enum. |

<details>
<summary><code>create()</code></summary>

#### `LoggerFactory create()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/LoggerFactory.php#L47)

```php
public static function create(string $channel, string $path, string $minLevel, int $maxFiles, ?string $cascadeErrorPath, ?\DateTimeZone $timezone): Logger
```

Creates a channel logger from a resolved channel config. When $cascadeErrorPath is provided a second ERROR+ handler is added to that file, allowing a channel to write high-severity events to a secondary log file as well.

| Param | Type | Description |
|---|---|---|
| `$channel` | `string` | Monolog channel name (used in log lines). |
| `$path` | `string` | Absolute path to the primary log file. |
| `$minLevel` | `string` | PSR-3 minimum level constant. |
| `$maxFiles` | `int` | Number of daily files to retain. |
| `$cascadeErrorPath` | `string\|null` | When set, ERROR+ records are also written here. |
| `$timezone` | `\DateTimeZone\|null` | Timezone for log timestamps; null defaults to UTC. |

**Returns:** `Logger`

</details>

<details>
<summary><code>toMonologLevel()</code></summary>

#### `LoggerFactory toMonologLevel()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/LoggerFactory.php#L83)

```php
public static function toMonologLevel(string $level): Level
```

Maps a PSR-3 level string to a Monolog Level enum.

| Param | Type | Description |
|---|---|---|
| `$level` | `string` | PSR-3 level constant. |

**Returns:** `Level`

</details>

## `RequestHeadersProcessor`

*class* — `rafalmasiarek\DashboardKit\Log\RequestHeadersProcessor` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestHeadersProcessor.php#L24)

Monolog processor that injects selected request headers into every log record. Configured once at container build time via $headers; populated per-request by calling boot() from RequestHeadersMiddleware. Configuration options for $headers: []        — no header injection (noop) '*'       — dump all request headers (prefixed req.*) string[]  — dump specific headers, e.g. ['User-Agent', 'X-Forwarded-For'] Log output example: req.user_agent="Mozilla/…"  req.x_forwarded_for="1.2.3.4"

**Depends on:** `\Closure`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$context` | `array<string, string>` | private | Context injected into every log record for the current request. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#requestheadersprocessor-__construct) | public | — | — |
| [`boot()`](#requestheadersprocessor-boot) | public | `void` | Populates the per-request context from the incoming request. Called once per request by RequestHeadersMiddleware. |
| [`__invoke()`](#requestheadersprocessor-__invoke) | public | `LogRecord` | Invoked by Monolog for every log record. Merges stored context into the record. Log-call context takes priority — the processor only fills in keys that are not already present. |
| [`headerKey()`](#requestheadersprocessor-headerkey) | private | `string` | Normalises a header name to a log context key: "X-Forwarded-For" → "req.x_forwarded_for". |

<details>
<summary><code>__construct()</code></summary>

#### `RequestHeadersProcessor __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestHeadersProcessor.php#L39)

```php
public function __construct(array|string $headers, ?\Closure $ipResolver)
```

| Param | Type | Description |
|---|---|---|
| `$headers` | `array<int, string>\|string` | []        — noop '*'       — all request headers string[]  — specific header names to include |
| `$ipResolver` | `\Closure\|null` | When provided, called with no arguments and must return string IP. Injected by Dashboard::buildContainer() as a lazy closure that reads from the container-bound RealIpResolver so that container overrides made after create() are always respected. Falls back to REMOTE_ADDR when null (e.g. standalone use without dashboard-kit). |

</details>

<details>
<summary><code>boot()</code></summary>

#### `RequestHeadersProcessor boot()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestHeadersProcessor.php#L52)

```php
public function boot(ServerRequestInterface $request): void
```

Populates the per-request context from the incoming request. Called once per request by RequestHeadersMiddleware.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | Incoming PSR-7 request. |

</details>

<details>
<summary><code>__invoke()</code></summary>

#### `RequestHeadersProcessor __invoke()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestHeadersProcessor.php#L86)

```php
public function __invoke(LogRecord $record): LogRecord
```

Invoked by Monolog for every log record. Merges stored context into the record. Log-call context takes priority — the processor only fills in keys that are not already present.

| Param | Type | Description |
|---|---|---|
| `$record` | `LogRecord` | — |

**Returns:** `LogRecord`

</details>

<details>
<summary><code>headerKey()</code></summary>

#### `RequestHeadersProcessor headerKey()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestHeadersProcessor.php#L101)

```php
private function headerKey(string $name): string
```

Normalises a header name to a log context key: "X-Forwarded-For" → "req.x_forwarded_for".

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Raw header name. |

**Returns:** `string`

</details>

## `RequestLogSanitizer`

*final class* — `rafalmasiarek\DashboardKit\Log\RequestLogSanitizer` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestLogSanitizer.php#L23)

Sanitizes request parameters before writing them to access logs. Rules applied to every field key (case-insensitive prefix/substring match): - Sensitive fields (password, secret, token, …) → replaced with "***" - Identity fields (email, login, username) → AES-256-CBC encrypted with APP_KEY - All other fields → logged as-is Encryption format: "enc:<base64(iv + ciphertext)>" Decrypt with: openssl_decrypt(base64_decode(substr($v, 4)), 'aes-256-cbc', $key, iv...) Security model: safe when log storage and APP_KEY are separated. Passwords are never stored — not even encrypted.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$key` | `string` | private readonly | Raw binary encryption key (32 bytes). |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#requestlogsanitizer-__construct) | public | — | — |
| [`sanitize()`](#requestlogsanitizer-sanitize) | public | `array<string, string>` | Sanitizes a flat key-value array of request parameters. Nested arrays (e.g. from multipart forms) are JSON-encoded before sanitizing. |
| [`encrypt()`](#requestlogsanitizer-encrypt) | public | `string` | Encrypts a plaintext value with AES-256-CBC. Returns "enc:<base64(iv + ciphertext)>" or the original value on failure. |
| [`decrypt()`](#requestlogsanitizer-decrypt) | public static | `string\|null` | Decrypts a value previously encrypted by encrypt(). |
| [`matches()`](#requestlogsanitizer-matches) | private | `bool` | Checks whether any pattern appears as a substring of the subject. |

<details>
<summary><code>__construct()</code></summary>

#### `RequestLogSanitizer __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestLogSanitizer.php#L43)

```php
public function __construct(string $appKey)
```

| Param | Type | Description |
|---|---|---|
| `$appKey` | `string` | Hex-encoded APP_KEY from environment (min 32 hex chars = 16 bytes). |

</details>

<details>
<summary><code>sanitize()</code></summary>

#### `RequestLogSanitizer sanitize()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestLogSanitizer.php#L56)

```php
public function sanitize(array $params): array
```

Sanitizes a flat key-value array of request parameters. Nested arrays (e.g. from multipart forms) are JSON-encoded before sanitizing.

| Param | Type | Description |
|---|---|---|
| `$params` | `array<string, mixed>` | Raw request parameters. |

**Returns:** `array<string, string>` — Sanitized parameters safe for logging.

</details>

<details>
<summary><code>encrypt()</code></summary>

#### `RequestLogSanitizer encrypt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestLogSanitizer.php#L92)

```php
public function encrypt(string $value): string
```

Encrypts a plaintext value with AES-256-CBC. Returns "enc:<base64(iv + ciphertext)>" or the original value on failure.

| Param | Type | Description |
|---|---|---|
| `$value` | `string` | Plaintext to encrypt. |

**Returns:** `string`

</details>

<details>
<summary><code>decrypt()</code></summary>

#### `RequestLogSanitizer decrypt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestLogSanitizer.php#L112)

```php
public static function decrypt(string $value, string $appKey): ?string
```

Decrypts a value previously encrypted by encrypt().

| Param | Type | Description |
|---|---|---|
| `$value` | `string` | Encrypted string in "enc:<base64>" format. |
| `$appKey` | `string` | APP_KEY used during encryption. |

**Returns:** `string\|null` — Decrypted plaintext, or null on failure.

</details>

<details>
<summary><code>matches()</code></summary>

#### `RequestLogSanitizer matches()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/RequestLogSanitizer.php#L141)

```php
private function matches(string $subject, array $patterns): bool
```

Checks whether any pattern appears as a substring of the subject.

| Param | Type | Description |
|---|---|---|
| `$subject` | `string` | Lowercase field name. |
| `$patterns` | `string[]` | List of substrings to match. |

**Returns:** `bool`

</details>

## `SecretRedactionProcessor`

*final class* — `rafalmasiarek\DashboardKit\Log\SecretRedactionProcessor` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/SecretRedactionProcessor.php#L28)

Monolog processor that redacts registered secret values (see SecretRegistry) from a record's message and context. SecretRegistry::redactValues() is otherwise only applied on the crash-path (the fatal-error shutdown handler and Slim's error handler) — any ad-hoc $logger->error('...', ['error' => $e->getMessage()]) call in application code bypasses it entirely. Pushing this processor onto a channel closes that gap for every log call on that channel, with no change required at the call site. Recurses into arrays. A Throwable found in context can't be mutated (its message is immutable), so it is replaced by a small class/message/file/line array with the message redacted, rather than being left to serialize its raw message during formatting.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__invoke()`](#secretredactionprocessor-__invoke) | public | `LogRecord` | — |
| [`redact()`](#secretredactionprocessor-redact) | private | `array<array-key, mixed>` | — |

<details>
<summary><code>__invoke()</code></summary>

#### `SecretRedactionProcessor __invoke()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/SecretRedactionProcessor.php#L34)

```php
public function __invoke(LogRecord $record): LogRecord
```

| Param | Type | Description |
|---|---|---|
| `$record` | `LogRecord` | — |

**Returns:** `LogRecord`

</details>

<details>
<summary><code>redact()</code></summary>

#### `SecretRedactionProcessor redact()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/SecretRedactionProcessor.php#L46)

```php
private function redact(array $value): array
```

| Param | Type | Description |
|---|---|---|
| `$value` | `array<array-key, mixed>` | — |

**Returns:** `array<array-key, mixed>`

</details>

## `StderrLogger`

*class* — `rafalmasiarek\DashboardKit\Log\StderrLogger` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/StderrLogger.php#L17)

Minimal PSR-3 logger that writes to stderr. Suitable for Docker and CLI environments where stderr is captured by the container runtime.

extends `AbstractLogger`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#stderrlogger-__construct) | public | — | — |
| [`log()`](#stderrlogger-log) | public | `void` | Writes a log entry to stderr. |
| [`interpolate()`](#stderrlogger-interpolate) | private | `string` | Replaces {placeholder} tokens in a message with context values. |

<details>
<summary><code>__construct()</code></summary>

#### `StderrLogger __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/StderrLogger.php#L33)

```php
public function __construct(string $minLevel)
```

| Param | Type | Description |
|---|---|---|
| `$minLevel` | `string` | Minimum log level to output (PSR-3 level constant). |

</details>

<details>
<summary><code>log()</code></summary>

#### `StderrLogger log()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/StderrLogger.php#L45)

```php
public function log( $level, string|Stringable $message, array $context): void
```

Writes a log entry to stderr.

| Param | Type | Description |
|---|---|---|
| `$level` | `mixed` | PSR-3 log level. |
| `$message` | `string\|Stringable` | Log message. |
| `$context` | `array<string, mixed>` | Additional context values. |

</details>

<details>
<summary><code>interpolate()</code></summary>

#### `StderrLogger interpolate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Log/StderrLogger.php#L73)

```php
private function interpolate(string $message, array $context): string
```

Replaces {placeholder} tokens in a message with context values.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | — |
| `$context` | `array<string, mixed>` | — |

**Returns:** `string`

</details>

## `MailDriverInterface`

*interface* — `rafalmasiarek\DashboardKit\Mail\Driver\MailDriverInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/MailDriverInterface.php#L15)

Contract for outgoing mail transport drivers. Implement this interface to add a new driver (Mailgun, SES, Postmark, …). Register it by returning the new class from the 'driver' match in Dashboard::buildContainer().

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`send()`](#maildriverinterface-send) | public | `void` | Delivers a single email message. |

<details>
<summary><code>send()</code></summary>

#### `MailDriverInterface send()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/MailDriverInterface.php#L24)

```php
public function send(OutboundMail $mail): void
```

Delivers a single email message.

| Param | Type | Description |
|---|---|---|
| `$mail` | [`OutboundMail`](#outboundmail) | Fully-resolved message to deliver. |

| Throws | When |
|---|---|
| [`MailException`](#mailexception) | When delivery fails. |

</details>

## `NullDriver`

*class* — `rafalmasiarek\DashboardKit\Mail\Driver\NullDriver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/NullDriver.php#L14)

Development driver that suppresses all outgoing mail silently. Use driver: 'null' in the mailer config to prevent real emails during local development or testing. Delivery outcomes are recorded by the Mailer via AuditLog — no additional logging is done here.

implements [`MailDriverInterface`](#maildriverinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`send()`](#nulldriver-send) | public | `void` | Delivers a single email message. |

<details>
<summary><code>send()</code></summary>

#### `NullDriver send()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/NullDriver.php#L19)

```php
public function send(OutboundMail $mail): void
```

Delivers a single email message.

| Param | Type | Description |
|---|---|---|
| `$mail` | [`OutboundMail`](#outboundmail) | Fully-resolved message to deliver. |

| Throws | When |
|---|---|
| [`MailException`](#mailexception) | When delivery fails. |

</details>

## `OutboundMail`

*final class* — `rafalmasiarek\DashboardKit\Mail\Driver\OutboundMail` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/OutboundMail.php#L12)

Fully-resolved message handed from Mailer to a MailDriverInterface implementation. Built once per send() call from a MailMessage plus its resolved html/text body — drivers read properties off this, nothing else.

**Depends on:** `\Closure`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#outboundmail-__construct) | public | — | — |

<details>
<summary><code>__construct()</code></summary>

#### `OutboundMail __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/OutboundMail.php#L42)

```php
public function __construct(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, ?string $replyTo, array $attachments, ?string $contentType, ?string $encoding, array $cc, array $bcc, array $embeds, array $customHeaders, ?string $messageId, ?string $inReplyTo, array $references, ?string $envelopeFrom, ?\Closure $onDebugLine)
```

| Param | Type | Description |
|---|---|---|
| `$fromEmail` | `string` | Sender address. |
| `$fromName` | `string` | Sender display name. |
| `$toEmail` | `string` | Recipient address. |
| `$toName` | `string` | Recipient display name (may be empty). |
| `$subject` | `string` | Email subject line. |
| `$htmlBody` | `string` | HTML version of the message body, or the full verbatim body when $contentType is set. |
| `$textBody` | `string` | Plain-text version of the message body. |
| `$replyTo` | `string\|null` | Reply-To address, or null. |
| `$attachments` | `list<array{path: string, name?: string, mimeType?: string}>` | Files to attach. |
| `$contentType` | `string\|null` | Overrides the normal alternative/mixed content-type construction when set — $htmlBody is then sent verbatim as the top-level body. |
| `$encoding` | `string\|null` | Content-Transfer-Encoding paired with $contentType; ignored when $contentType is null. |
| `$cc` | `list<array{email: string, name?: string}>` | Carbon-copy recipients (header + envelope). |
| `$bcc` | `list<array{email: string, name?: string}>` | Blind carbon-copy recipients (envelope only, never in headers). |
| `$embeds` | `list<array{path: string, cid: string, name?: string, mimeType?: string}>` | Inline parts referenced from the HTML body as "cid:...". |
| `$customHeaders` | `array<string, string>` | Additional raw header lines, name => value. |
| `$messageId` | `string\|null` | Value for the Message-ID header; a driver generates one when null. |
| `$inReplyTo` | `string\|null` | Message-ID this message replies to. |
| `$references` | `list<string>` | Message-IDs for the References header. |
| `$envelopeFrom` | `string\|null` | SMTP envelope sender (MAIL FROM); defaults to $fromEmail when null. |
| `$onDebugLine` | `(\Closure(string): void)\|null` | Invoked with each raw transport transcript line, when supported by the driver. |

</details>

## `SmtpDriver`

*class* — `rafalmasiarek\DashboardKit\Mail\Driver\SmtpDriver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/SmtpDriver.php#L37)

Delivers mail via SMTP using rafalmasiarek/mailer's from-scratch SmtpClient. Config keys (passed as $config array): host       — SMTP server hostname port       — SMTP port (default: 587) username   — SMTP username (leave empty to disable auth) password   — SMTP password encryption — 'tls' (STARTTLS), 'ssl' (implicit TLS), or '' (none) dkim       — optional ['private_key' => PEM string, 'domain' => ..., 'selector' => ..., 'headers' => list<string> (default: From, To, Subject, Date, Message-ID)] Signing is skipped entirely when 'private_key' is empty/absent. tls        — optional ['verify_peer' => bool (default true), 'ca_file' => string, 'client_cert_file' => string, 'client_key_file' => string, 'client_key_passphrase' => string] dsn        — optional ['enabled' => bool (default false), 'ret' => 'HDRS'|'FULL', 'notify' => list<'SUCCESS'|'FAILURE'|'DELAY'|'NEVER'>]. Applied to every message when enabled; silently ignored by servers that don't advertise DSN.

implements [`MailDriverInterface`](#maildriverinterface)

**Depends on:** `DnsResolverInterface`, `DeadLetterStoreInterface`, `TlsOptions`, `DsnOptions`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$tls` | `TlsOptions` | private readonly | — |
| `$dsn` | `?DsnOptions` | private readonly | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#smtpdriver-__construct) | public | — | — |
| [`send()`](#smtpdriver-send) | public | `void` | Delivers a single email message. |
| [`maybeSign()`](#smtpdriver-maybesign) | private | `string` | Signs the message with DKIM when config['dkim']['private_key'] is set; returns it unchanged otherwise. |
| [`generateMessageId()`](#smtpdriver-generatemessageid) | private static | `string` | Generates a Message-ID local part + domain from the sender address. |

<details>
<summary><code>__construct()</code></summary>

#### `SmtpDriver __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/SmtpDriver.php#L48)

```php
public function __construct(array $config, DnsResolverInterface $dns, ?DeadLetterStoreInterface $deadLetterStore)
```

| Param | Type | Description |
|---|---|---|
| `$config` | `array<string, mixed>` | SMTP connection parameters. |
| `$dns` | `DnsResolverInterface` | Resolver used to pin the connection's target IP. |
| `$deadLetterStore` | `DeadLetterStoreInterface\|null` | Receives a FailedDelivery on any send failure, when set. |

</details>

<details>
<summary><code>send()</code></summary>

#### `SmtpDriver send()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/SmtpDriver.php#L74)

```php
public function send(OutboundMail $mail): void
```

Delivers a single email message.

| Param | Type | Description |
|---|---|---|
| `$mail` | [`OutboundMail`](#outboundmail) | Fully-resolved message to deliver. |

| Throws | When |
|---|---|
| [`MailException`](#mailexception) | When delivery fails. |

</details>

<details>
<summary><code>maybeSign()</code></summary>

#### `SmtpDriver maybeSign()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/SmtpDriver.php#L149)

```php
private function maybeSign(string $rawMessage): string
```

Signs the message with DKIM when config['dkim']['private_key'] is set; returns it unchanged otherwise.

| Param | Type | Description |
|---|---|---|
| `$rawMessage` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>generateMessageId()</code></summary>

#### `SmtpDriver generateMessageId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Driver/SmtpDriver.php#L177)

```php
private static function generateMessageId(string $fromEmail): string
```

Generates a Message-ID local part + domain from the sender address.

| Param | Type | Description |
|---|---|---|
| `$fromEmail` | `string` | — |

**Returns:** `string`

</details>

## `MailException`

*class* — `rafalmasiarek\DashboardKit\Mail\Exception\MailException` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Exception/MailException.php#L10)

Thrown when an outgoing mail cannot be delivered.

extends `\RuntimeException`

## `MailMessage`

*class* — `rafalmasiarek\DashboardKit\Mail\MailMessage` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L19)

Fluent builder for a single outgoing email message. Usage: MailMessage::to('user@example.com', 'Jane') ->subject('Activate your account') ->template('emails/activation.twig', ['link' => $url]); Either template() or html() must be set before passing to Mailer::send().

**Depends on:** `RawMimeBody`, `\Closure`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$toName` | `string` | private | Recipient display name. |
| `$subject` | `string` | private | Email subject line. |
| `$template` | `string\|null` | private | Twig template path relative to configured template directories. |
| `$variables` | `array<string, mixed>` | private | Variables passed to the Twig template. |
| `$bodyHtmlAppends` | `list<array{html: string, priority: int}>` | private | Raw HTML fragments queued via appendBodyHtml(), flattened into the 'extra_body_html' template variable (sorted by priority) on read. |
| `$htmlBody` | `string\|null` | private | Explicit HTML body (used when template is not set). |
| `$textBody` | `string\|null` | private | Explicit plain-text body (auto-generated from HTML when null). |
| `$replyTo` | `string\|null` | private | Reply-To address. |
| `$attachments` | `list<array{path: string, name?: string, mimeType?: string}>` | private | — |
| `$rawBody` | `RawMimeBody\|null` | private | — |
| `$cc` | `list<array{email: string, name?: string}>` | private | — |
| `$bcc` | `list<array{email: string, name?: string}>` | private | — |
| `$embeds` | `list<array{path: string, cid: string, name?: string, mimeType?: string}>` | private | — |
| `$customHeaders` | `array<string, string>` | private | — |
| `$messageId` | `string\|null` | private | Caller-assigned Message-ID; a driver generates one when null. |
| `$inReplyTo` | `string\|null` | private | Message-ID this message replies to. |
| `$references` | `list<string>` | private | — |
| `$envelopeFrom` | `string\|null` | private | SMTP envelope sender (MAIL FROM); defaults to the Mailer's fromEmail when null. |
| `$onDebugLine` | `\Closure(string):` | private | void\|null |
| `$correlationId` | `string\|null` | private | Caller-supplied id for audit/log correlation only — never touches SMTP/MIME. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#mailmessage-__construct) | private | — | — |
| [`to()`](#mailmessage-to) | public static | `self` | Creates a new message addressed to the given recipient. |
| [`subject()`](#mailmessage-subject) | public | `self` | Sets the email subject line. |
| [`template()`](#mailmessage-template) | public | `self` | Sets the Twig template to render as the message body. The template path is resolved via the configured Twig loaders — application templates take precedence over built-in ones. |
| [`variable()`](#mailmessage-variable) | public | `self` | Merges one additional template variable in, without disturbing any others already set — the only way to add a variable after template() has already replaced the full set. Lets a Mailer decorator inject its own value (e.g. appending to the 'extra_body_html' list layout.twig renders) into whatever message it's wrapping, without needing to know or preserve the caller's other variables. |
| [`appendBodyHtml()`](#mailmessage-appendbodyhtml) | public | `self` | Queues a raw HTML fragment to render after the main content, via the 'extra_body_html' loop in layout.twig — the generic extension point for a Mailer decorator to append something to every email (e.g. an open-tracking pixel) without layout.twig knowing what it is. Fragments render in ascending $priority order (ties keep insertion order) — a tracking pixel should use a high priority (e.g. 999) to guarantee it renders after anything else appended. |
| [`html()`](#mailmessage-html) | public | `self` | Sets an explicit HTML body, bypassing Twig rendering. |
| [`text()`](#mailmessage-text) | public | `self` | Sets an explicit plain-text body. When not set, Mailer auto-generates one by stripping tags from the HTML body. |
| [`replyTo()`](#mailmessage-replyto) | public | `self` | Sets the Reply-To address. |
| [`attach()`](#mailmessage-attach) | public | `self` | Queues a file attachment. |
| [`cc()`](#mailmessage-cc) | public | `self` | Adds a carbon-copy recipient. Call multiple times for multiple recipients. |
| [`bcc()`](#mailmessage-bcc) | public | `self` | Adds a blind carbon-copy recipient — envelope-only, never appears in any header. Call multiple times for multiple recipients. |
| [`embed()`](#mailmessage-embed) | public | `self` | Queues an inline part referenced from the HTML body as "cid:$cid". Call multiple times for multiple embeds. |
| [`customHeader()`](#mailmessage-customheader) | public | `self` | Sets an additional raw header. Call multiple times for multiple headers; a repeated $name overwrites the earlier value. |
| [`messageId()`](#mailmessage-messageid) | public | `self` | Sets the Message-ID header value. A driver generates one when unset. |
| [`inReplyTo()`](#mailmessage-inreplyto) | public | `self` | Sets the In-Reply-To header, threading this message to a prior one. |
| [`references()`](#mailmessage-references) | public | `self` | Sets the References header. |
| [`envelopeFrom()`](#mailmessage-envelopefrom) | public | `self` | Sets the SMTP envelope sender (MAIL FROM), independent of the visible From: header. Useful for routing bounces to a dedicated mailbox. |
| [`onDebugLine()`](#mailmessage-ondebugline) | public | `self` | Registers a callback invoked with each raw transport transcript line, when supported by the active driver. |
| [`correlationId()`](#mailmessage-correlationid) | public | `self` | Sets an opaque id used purely for audit-log and hook correlation (AuditLog::mailAttempt()/mailOpen(), and readable by any hook listener off the message itself) — never written to any header or the SMTP envelope. Use messageId() instead when the id must appear on the wire. |
| [`rawBody()`](#mailmessage-rawbody) | public | `self` | Sets a verbatim MIME body, bypassing template()/html()/text() resolution entirely. Used for content types the normal alternative/mixed structure cannot represent. |
| [`getToEmail()`](#mailmessage-gettoemail) | public | `string` | — |
| [`getToName()`](#mailmessage-gettoname) | public | `string` | — |
| [`getSubject()`](#mailmessage-getsubject) | public | `string` | — |
| [`getTemplate()`](#mailmessage-gettemplate) | public | `string\|null` | — |
| [`getVariables()`](#mailmessage-getvariables) | public | `array<string, mixed>` | Returns the template variables, with any appendBodyHtml() fragments flattened into 'extra_body_html' (sorted by priority ascending) — overwrites a same-named key set via template()'s own $variables. |
| [`getHtmlBody()`](#mailmessage-gethtmlbody) | public | `string\|null` | — |
| [`getTextBody()`](#mailmessage-gettextbody) | public | `string\|null` | — |
| [`getReplyTo()`](#mailmessage-getreplyto) | public | `string\|null` | — |
| [`getAttachments()`](#mailmessage-getattachments) | public | `list<array{path: string, name?: string, mimeType?: string}>` | — |
| [`getRawBody()`](#mailmessage-getrawbody) | public | `RawMimeBody\|null` | — |
| [`getCc()`](#mailmessage-getcc) | public | `list<array{email: string, name?: string}>` | — |
| [`getBcc()`](#mailmessage-getbcc) | public | `list<array{email: string, name?: string}>` | — |
| [`getEmbeds()`](#mailmessage-getembeds) | public | `list<array{path: string, cid: string, name?: string, mimeType?: string}>` | — |
| [`getCustomHeaders()`](#mailmessage-getcustomheaders) | public | `array<string, string>` | — |
| [`getMessageId()`](#mailmessage-getmessageid) | public | `string\|null` | — |
| [`getInReplyTo()`](#mailmessage-getinreplyto) | public | `string\|null` | — |
| [`getReferences()`](#mailmessage-getreferences) | public | `list<string>` | — |
| [`getEnvelopeFrom()`](#mailmessage-getenvelopefrom) | public | `string\|null` | — |
| [`getDebugLineCallback()`](#mailmessage-getdebuglinecallback) | public | `\Closure(string):` | — |
| [`getCorrelationId()`](#mailmessage-getcorrelationid) | public | `string\|null` | — |

<details>
<summary><code>__construct()</code></summary>

#### `MailMessage __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L89)

```php
private function __construct(string $toEmail)
```

| Param | Type | Description |
|---|---|---|
| `$toEmail` | `string` | Recipient address. |

</details>

<details>
<summary><code>to()</code></summary>

#### `MailMessage to()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L101)

```php
public static function to(string $email, string $name): self
```

Creates a new message addressed to the given recipient.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | Recipient email address. |
| `$name` | `string` | Recipient display name (optional). |

**Returns:** `self`

</details>

<details>
<summary><code>subject()</code></summary>

#### `MailMessage subject()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L115)

```php
public function subject(string $subject): self
```

Sets the email subject line.

| Param | Type | Description |
|---|---|---|
| `$subject` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>template()</code></summary>

#### `MailMessage template()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L132)

```php
public function template(string $path, array $variables): self
```

Sets the Twig template to render as the message body. The template path is resolved via the configured Twig loaders — application templates take precedence over built-in ones.

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | Template path (e.g. 'emails/activation.twig'). |
| `$variables` | `array<string, mixed>` | Variables passed to the template. |

**Returns:** `self`

</details>

<details>
<summary><code>variable()</code></summary>

#### `MailMessage variable()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L152)

```php
public function variable(string $key, mixed $value): self
```

Merges one additional template variable in, without disturbing any others already set — the only way to add a variable after template() has already replaced the full set. Lets a Mailer decorator inject its own value (e.g. appending to the 'extra_body_html' list layout.twig renders) into whatever message it's wrapping, without needing to know or preserve the caller's other variables.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$value` | `mixed` | — |

**Returns:** `self`

</details>

<details>
<summary><code>appendBodyHtml()</code></summary>

#### `MailMessage appendBodyHtml()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L173)

```php
public function appendBodyHtml(string $html, int $priority): self
```

Queues a raw HTML fragment to render after the main content, via the 'extra_body_html' loop in layout.twig — the generic extension point for a Mailer decorator to append something to every email (e.g. an open-tracking pixel) without layout.twig knowing what it is. Fragments render in ascending $priority order (ties keep insertion order) — a tracking pixel should use a high priority (e.g. 999) to guarantee it renders after anything else appended.

| Param | Type | Description |
|---|---|---|
| `$html` | `string` | — |
| `$priority` | `int` | Lower renders first; default 0. |

**Returns:** `self`

</details>

<details>
<summary><code>html()</code></summary>

#### `MailMessage html()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L186)

```php
public function html(string $html): self
```

Sets an explicit HTML body, bypassing Twig rendering.

| Param | Type | Description |
|---|---|---|
| `$html` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>text()</code></summary>

#### `MailMessage text()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L201)

```php
public function text(string $text): self
```

Sets an explicit plain-text body. When not set, Mailer auto-generates one by stripping tags from the HTML body.

| Param | Type | Description |
|---|---|---|
| `$text` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>replyTo()</code></summary>

#### `MailMessage replyTo()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L214)

```php
public function replyTo(string $email): self
```

Sets the Reply-To address.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>attach()</code></summary>

#### `MailMessage attach()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L229)

```php
public function attach(string $path, string $name, ?string $mimeType): self
```

Queues a file attachment.

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | Absolute path to the file. |
| `$name` | `string` | Attachment filename; defaults to the file's own basename. |
| `$mimeType` | `string\|null` | Explicit Content-Type; guessed from the file when null. |

**Returns:** `self`

</details>

<details>
<summary><code>cc()</code></summary>

#### `MailMessage cc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L250)

```php
public function cc(string $email, string $name): self
```

Adds a carbon-copy recipient. Call multiple times for multiple recipients.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$name` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>bcc()</code></summary>

#### `MailMessage bcc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L265)

```php
public function bcc(string $email, string $name): self
```

Adds a blind carbon-copy recipient — envelope-only, never appears in any header. Call multiple times for multiple recipients.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$name` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>embed()</code></summary>

#### `MailMessage embed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L282)

```php
public function embed(string $path, string $cid, string $name, ?string $mimeType): self
```

Queues an inline part referenced from the HTML body as "cid:$cid". Call multiple times for multiple embeds.

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | Absolute path to the file. |
| `$cid` | `string` | Content-ID to reference as "cid:$cid" in the HTML body. |
| `$name` | `string` | Attachment filename; defaults to the file's own basename. |
| `$mimeType` | `string\|null` | Explicit Content-Type; guessed from the file when null. |

**Returns:** `self`

</details>

<details>
<summary><code>customHeader()</code></summary>

#### `MailMessage customHeader()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L304)

```php
public function customHeader(string $name, string $value): self
```

Sets an additional raw header. Call multiple times for multiple headers; a repeated $name overwrites the earlier value.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$value` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>messageId()</code></summary>

#### `MailMessage messageId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L317)

```php
public function messageId(string $id): self
```

Sets the Message-ID header value. A driver generates one when unset.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | Without angle brackets. |

**Returns:** `self`

</details>

<details>
<summary><code>inReplyTo()</code></summary>

#### `MailMessage inReplyTo()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L330)

```php
public function inReplyTo(string $messageId): self
```

Sets the In-Reply-To header, threading this message to a prior one.

| Param | Type | Description |
|---|---|---|
| `$messageId` | `string` | Without angle brackets. |

**Returns:** `self`

</details>

<details>
<summary><code>references()</code></summary>

#### `MailMessage references()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L343)

```php
public function references(array $messageIds): self
```

Sets the References header.

| Param | Type | Description |
|---|---|---|
| `$messageIds` | `list<string>` | Without angle brackets. |

**Returns:** `self`

</details>

<details>
<summary><code>envelopeFrom()</code></summary>

#### `MailMessage envelopeFrom()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L357)

```php
public function envelopeFrom(string $email): self
```

Sets the SMTP envelope sender (MAIL FROM), independent of the visible From: header. Useful for routing bounces to a dedicated mailbox.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>onDebugLine()</code></summary>

#### `MailMessage onDebugLine()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L371)

```php
public function onDebugLine(callable $callback): self
```

Registers a callback invoked with each raw transport transcript line, when supported by the active driver.

| Param | Type | Description |
|---|---|---|
| `$callback` | `callable` | — |

**Returns:** `self`

</details>

<details>
<summary><code>correlationId()</code></summary>

#### `MailMessage correlationId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L387)

```php
public function correlationId(string $id): self
```

Sets an opaque id used purely for audit-log and hook correlation (AuditLog::mailAttempt()/mailOpen(), and readable by any hook listener off the message itself) — never written to any header or the SMTP envelope. Use messageId() instead when the id must appear on the wire.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | — |

**Returns:** `self`

</details>

<details>
<summary><code>rawBody()</code></summary>

#### `MailMessage rawBody()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L402)

```php
public function rawBody(RawMimeBody $body): self
```

Sets a verbatim MIME body, bypassing template()/html()/text() resolution entirely. Used for content types the normal alternative/mixed structure cannot represent.

| Param | Type | Description |
|---|---|---|
| `$body` | `RawMimeBody` | — |

**Returns:** `self`

</details>

<details>
<summary><code>getToEmail()</code></summary>

#### `MailMessage getToEmail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L411)

```php
public function getToEmail(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>getToName()</code></summary>

#### `MailMessage getToName()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L419)

```php
public function getToName(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>getSubject()</code></summary>

#### `MailMessage getSubject()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L427)

```php
public function getSubject(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>getTemplate()</code></summary>

#### `MailMessage getTemplate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L435)

```php
public function getTemplate(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getVariables()</code></summary>

#### `MailMessage getVariables()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L447)

```php
public function getVariables(): array
```

Returns the template variables, with any appendBodyHtml() fragments flattened into 'extra_body_html' (sorted by priority ascending) — overwrites a same-named key set via template()'s own $variables.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>getHtmlBody()</code></summary>

#### `MailMessage getHtmlBody()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L462)

```php
public function getHtmlBody(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getTextBody()</code></summary>

#### `MailMessage getTextBody()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L470)

```php
public function getTextBody(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getReplyTo()</code></summary>

#### `MailMessage getReplyTo()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L478)

```php
public function getReplyTo(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getAttachments()</code></summary>

#### `MailMessage getAttachments()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L486)

```php
public function getAttachments(): array
```

**Returns:** `list<array{path: string, name?: string, mimeType?: string}>`

</details>

<details>
<summary><code>getRawBody()</code></summary>

#### `MailMessage getRawBody()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L494)

```php
public function getRawBody(): ?RawMimeBody
```

**Returns:** `RawMimeBody\|null`

</details>

<details>
<summary><code>getCc()</code></summary>

#### `MailMessage getCc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L502)

```php
public function getCc(): array
```

**Returns:** `list<array{email: string, name?: string}>`

</details>

<details>
<summary><code>getBcc()</code></summary>

#### `MailMessage getBcc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L510)

```php
public function getBcc(): array
```

**Returns:** `list<array{email: string, name?: string}>`

</details>

<details>
<summary><code>getEmbeds()</code></summary>

#### `MailMessage getEmbeds()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L518)

```php
public function getEmbeds(): array
```

**Returns:** `list<array{path: string, cid: string, name?: string, mimeType?: string}>`

</details>

<details>
<summary><code>getCustomHeaders()</code></summary>

#### `MailMessage getCustomHeaders()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L526)

```php
public function getCustomHeaders(): array
```

**Returns:** `array<string, string>`

</details>

<details>
<summary><code>getMessageId()</code></summary>

#### `MailMessage getMessageId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L534)

```php
public function getMessageId(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getInReplyTo()</code></summary>

#### `MailMessage getInReplyTo()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L542)

```php
public function getInReplyTo(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getReferences()</code></summary>

#### `MailMessage getReferences()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L550)

```php
public function getReferences(): array
```

**Returns:** `list<string>`

</details>

<details>
<summary><code>getEnvelopeFrom()</code></summary>

#### `MailMessage getEnvelopeFrom()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L558)

```php
public function getEnvelopeFrom(): ?string
```

**Returns:** `string\|null`

</details>

<details>
<summary><code>getDebugLineCallback()</code></summary>

#### `MailMessage getDebugLineCallback()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L566)

```php
public function getDebugLineCallback(): ?\Closure
```

**Returns:** `\Closure(string):` — void|null

</details>

<details>
<summary><code>getCorrelationId()</code></summary>

#### `MailMessage getCorrelationId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailMessage.php#L574)

```php
public function getCorrelationId(): ?string
```

**Returns:** `string\|null`

</details>

## `Mailer`

*class* — `rafalmasiarek\DashboardKit\Mail\Mailer` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Mailer.php#L34)

Sends outgoing emails by rendering Twig templates and delegating to a transport driver. Registered unconditionally in the DI container as Mailer::class. The active driver is determined by config['mailer']['driver']: 'smtp'  — SmtpDriver (rafalmasiarek/mailer, no PHPMailer dependency) 'null'  — NullDriver (logs and discards; default when unset) Adding a new driver: implement MailDriverInterface and add a case to the match expression in Dashboard::buildContainer(). Hooks emitted: mail_sending (MailMessage $message)               — before delivery, via HookRegistry::addListener() only; a listener may still mutate $message (e.g. appendBodyHtml()) and can never block delivery, since a throwing listener is caught and logged by HookRegistry::emit() itself mail_sent    (MailMessage $message)               — after successful delivery mail_failed  (MailMessage $message, \Throwable $e) — on delivery failure (exception is re-thrown)

implements [`MailerInterface`](#mailerinterface)

**Depends on:** [`MailDriverInterface`](#maildriverinterface), `Twig`, [`HookRegistry`](#hookregistry), [`AuditLog`](#auditlog)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#mailer-__construct) | public | — | — |
| [`send()`](#mailer-send) | public | `void` | Emits 'mail_sending', then renders the message, hands it off to the configured driver, and emits a result hook. Before anything else: emits 'mail_sending' with (MailMessage $message) — addListener()-only, so a plugin that wants to mutate $message before delivery (e.g. append a tracking pixel) always runs here, never as a replacement for this class's own MailerInterface binding. On success: emits 'mail_sent' with (MailMessage $message). On failure: emits 'mail_failed' with (MailMessage $message, \Throwable $e), then re-throws. |
| [`resolveBody()`](#mailer-resolvebody) | private | `array{htmlBody: string, textBody: string}` | Resolves the final HTML and plain-text bodies from the message. When a Twig template is set it takes precedence over an explicit html() body. The plain-text body is auto-generated from the rendered HTML when not explicitly set. |
| [`insertBeforeClosingTag()`](#mailer-insertbeforeclosingtag) | private static | `string` | Inserts $fragment before </body> when present, else before </html>, else appends it verbatim. $html may be a bare content fragment (no document wrapper at all, e.g. the built-in email templates) or a complete document (e.g. an app-built HTML shell) — a blind append would land $fragment after </html> in the latter case, which is invalid HTML. |
| [`htmlToText()`](#mailer-htmltotext) | private static | `string` | Strips HTML tags and normalises whitespace to produce a plain-text fallback. |

<details>
<summary><code>__construct()</code></summary>

#### `Mailer __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Mailer.php#L44)

```php
public function __construct(MailDriverInterface $driver, Twig $view, string $fromEmail, string $fromName, HookRegistry $hooks, AuditLog $audit)
```

| Param | Type | Description |
|---|---|---|
| `$driver` | [`MailDriverInterface`](#maildriverinterface) | Active transport driver. |
| `$view` | `Twig` | Twig engine used to render email templates. |
| `$fromEmail` | `string` | Default sender address. |
| `$fromName` | `string` | Default sender display name. |
| `$hooks` | [`HookRegistry`](#hookregistry) | Hook registry — fires mail_sent / mail_failed events. |
| `$audit` | [`AuditLog`](#auditlog) | Audit logger — records structured delivery outcomes. |

</details>

<details>
<summary><code>send()</code></summary>

#### `Mailer send()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Mailer.php#L70)

```php
public function send(MailMessage $message): void
```

Emits 'mail_sending', then renders the message, hands it off to the configured driver, and emits a result hook. Before anything else: emits 'mail_sending' with (MailMessage $message) — addListener()-only, so a plugin that wants to mutate $message before delivery (e.g. append a tracking pixel) always runs here, never as a replacement for this class's own MailerInterface binding. On success: emits 'mail_sent'   with (MailMessage $message). On failure: emits 'mail_failed' with (MailMessage $message, \Throwable $e), then re-throws.

| Param | Type | Description |
|---|---|---|
| `$message` | [`MailMessage`](#mailmessage) | Message to send. |

| Throws | When |
|---|---|
| [`MailException`](#mailexception) | When the driver reports a delivery failure. |
| `\InvalidArgumentException` | When neither template nor html body is set. |

</details>

<details>
<summary><code>resolveBody()</code></summary>

#### `Mailer resolveBody()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Mailer.php#L133)

```php
private function resolveBody(MailMessage $message): array
```

Resolves the final HTML and plain-text bodies from the message. When a Twig template is set it takes precedence over an explicit html() body. The plain-text body is auto-generated from the rendered HTML when not explicitly set.

| Param | Type | Description |
|---|---|---|
| `$message` | [`MailMessage`](#mailmessage) | — |

**Returns:** `array{htmlBody: string, textBody: string}` — [htmlBody, textBody]

| Throws | When |
|---|---|
| `\InvalidArgumentException` | When neither template nor html body is provided. |

</details>

<details>
<summary><code>insertBeforeClosingTag()</code></summary>

#### `Mailer insertBeforeClosingTag()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Mailer.php#L173)

```php
private static function insertBeforeClosingTag(string $html, string $fragment): string
```

Inserts $fragment before </body> when present, else before </html>, else appends it verbatim. $html may be a bare content fragment (no document wrapper at all, e.g. the built-in email templates) or a complete document (e.g. an app-built HTML shell) — a blind append would land $fragment after </html> in the latter case, which is invalid HTML.

| Param | Type | Description |
|---|---|---|
| `$html` | `string` | — |
| `$fragment` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>htmlToText()</code></summary>

#### `Mailer htmlToText()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/Mailer.php#L191)

```php
private static function htmlToText(string $html): string
```

Strips HTML tags and normalises whitespace to produce a plain-text fallback.

| Param | Type | Description |
|---|---|---|
| `$html` | `string` | — |

**Returns:** `string`

</details>

## `MailerInterface`

*interface* — `rafalmasiarek\DashboardKit\Mail\MailerInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailerInterface.php#L19)

Sends outgoing emails rendered from a MailMessage. Bound in the DI container as Mailer::class (the concrete implementation) and MailerInterface::class (resolved to the same instance). Consumers should type-hint the interface, not Mailer directly, so an app can substitute a decorator (e.g. one that adds open-tracking) by overriding the MailerInterface::class binding after Dashboard::create() — the same pattern already used for RealIpResolver/GeoIpDriverInterface.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`send()`](#mailerinterface-send) | public | `void` | Renders the message and hands it off to the configured transport driver. On success: emits 'mail_sent' with (MailMessage $message). On failure: emits 'mail_failed' with (MailMessage $message, \Throwable $e), then re-throws. |

<details>
<summary><code>send()</code></summary>

#### `MailerInterface send()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Mail/MailerInterface.php#L32)

```php
public function send(MailMessage $message): void
```

Renders the message and hands it off to the configured transport driver. On success: emits 'mail_sent'   with (MailMessage $message). On failure: emits 'mail_failed' with (MailMessage $message, \Throwable $e), then re-throws.

| Param | Type | Description |
|---|---|---|
| `$message` | [`MailMessage`](#mailmessage) | Message to send. |

| Throws | When |
|---|---|
| `Exception\MailException` | When the driver reports a delivery failure. |
| `\InvalidArgumentException` | When neither template nor html body is set. |

</details>

## `AuthMiddleware`

*class* — `rafalmasiarek\DashboardKit\Middleware\AuthMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/AuthMiddleware.php#L17)

Redirects unauthenticated requests to the login page.

implements `MiddlewareInterface`

**Depends on:** `Auth`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#authmiddleware-__construct) | public | — | — |
| [`process()`](#authmiddleware-process) | public | `ResponseInterface` | Passes the request through if authenticated, redirects to /login otherwise. Appends a ?from= query parameter so the login controller can redirect back to the original URL after successful authentication. |

<details>
<summary><code>__construct()</code></summary>

#### `AuthMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/AuthMiddleware.php#L24)

```php
public function __construct(Auth $auth, string $dashboardUrlPrefix)
```

| Param | Type | Description |
|---|---|---|
| `$auth` | `Auth` | AuthKit authentication service. |
| `$dashboardUrlPrefix` | `string` | Full URL prefix for the dashboard (e.g. '/api/admin'). Prepended to the /login redirect target. |

</details>

<details>
<summary><code>process()</code></summary>

#### `AuthMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/AuthMiddleware.php#L40)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

Passes the request through if authenticated, redirects to /login otherwise. Appends a ?from= query parameter so the login controller can redirect back to the original URL after successful authentication.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | Request instance. |
| `$handler` | `RequestHandlerInterface` | Next handler in the chain. |

**Returns:** `ResponseInterface`

</details>

## `CsrfMiddleware`

*class* — `rafalmasiarek\DashboardKit\Middleware\CsrfMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/CsrfMiddleware.php#L22)

Validates CSRF tokens on state-changing HTTP methods. Expects a hidden field named _csrf in the request body. The token is generated in Twig templates via {{ csrf.generate() }} using the 'csrf' Twig global. GET and HEAD requests are passed through without checking.

implements `MiddlewareInterface`

**Depends on:** `Csrf`, `RealIpResolver`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#csrfmiddleware-__construct) | public | — | — |
| [`process()`](#csrfmiddleware-process) | public | `ResponseInterface` | Validates the _csrf field for POST/PUT/PATCH/DELETE requests. For form submissions reads _csrf + _csrf_container from the parsed body. For JSON fetch requests (Content-Type: application/json) falls back to reading the same fields from the raw JSON body, also accepting the legacy csrf_token field name used by admin-requests.js. Returns HTTP 419 when the token is missing or invalid. |

<details>
<summary><code>__construct()</code></summary>

#### `CsrfMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/CsrfMiddleware.php#L32)

```php
public function __construct(Csrf $csrf, RealIpResolver $ipResolver)
```

| Param | Type | Description |
|---|---|---|
| `$csrf` | `Csrf` | CSRF token service. |
| `$ipResolver` | `RealIpResolver` | Resolves the real client IP behind trusted proxies (e.g. Cloudflare), so bind_ip validates against the actual visitor rather than an edge/proxy address. |

</details>

<details>
<summary><code>process()</code></summary>

#### `CsrfMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/CsrfMiddleware.php#L52)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

Validates the _csrf field for POST/PUT/PATCH/DELETE requests. For form submissions reads _csrf + _csrf_container from the parsed body. For JSON fetch requests (Content-Type: application/json) falls back to reading the same fields from the raw JSON body, also accepting the legacy csrf_token field name used by admin-requests.js. Returns HTTP 419 when the token is missing or invalid.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | Incoming request. |
| `$handler` | `RequestHandlerInterface` | Next middleware in chain. |

**Returns:** `ResponseInterface`

</details>

## `RequestHeadersMiddleware`

*class* — `rafalmasiarek\DashboardKit\Middleware\RequestHeadersMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/RequestHeadersMiddleware.php#L19)

PSR-15 middleware that boots all registered RequestHeadersProcessor instances. Added to the Slim stack automatically by Dashboard when any logging channel has request_headers configured (globally or per-channel).

implements `MiddlewareInterface`

**Depends on:** [`RequestHeadersProcessor`](#requestheadersprocessor)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$processors` | `RequestHeadersProcessor[]` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#requestheadersmiddleware-__construct) | public | — | — |
| [`process()`](#requestheadersmiddleware-process) | public | `ResponseInterface` | Boots all processors with the current request then passes control downstream. |

<details>
<summary><code>__construct()</code></summary>

#### `RequestHeadersMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/RequestHeadersMiddleware.php#L27)

```php
public function __construct(RequestHeadersProcessor $processors)
```

| Param | Type | Description |
|---|---|---|
| `$processors` | [`RequestHeadersProcessor`](#requestheadersprocessor) | — |

</details>

<details>
<summary><code>process()</code></summary>

#### `RequestHeadersMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/RequestHeadersMiddleware.php#L39)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

Boots all processors with the current request then passes control downstream.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | PSR-7 server request. |
| `$handler` | `RequestHandlerInterface` | Next middleware or route handler. |

**Returns:** `ResponseInterface`

</details>

## `RoleMiddleware`

*class* — `rafalmasiarek\DashboardKit\Middleware\RoleMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/RoleMiddleware.php#L25)

Enforces role-based access on top of authentication. Must be used after AuthMiddleware — assumes the user is already logged in. Redirects to / with 403 status when the user's role is not in the allowed list. Module usage: 'auth_required' => 'admin' 'auth_required' => ['admin', 'editor']

implements `MiddlewareInterface`

**Depends on:** `ContainerInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#rolemiddleware-__construct) | public | — | — |
| [`process()`](#rolemiddleware-process) | public | `ResponseInterface` | Passes the request through if the current user's role is in the allowed list. |

<details>
<summary><code>__construct()</code></summary>

#### `RoleMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/RoleMiddleware.php#L32)

```php
public function __construct(ContainerInterface $container, array $roles, string $dashboardUrlPrefix)
```

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | PSR-11 container (Auth resolved lazily per request). |
| `$roles` | `string[]` | Allowed role values. |
| `$dashboardUrlPrefix` | `string` | Full URL prefix for dashboard routes (base_path + dashboard.prefix). |

</details>

<details>
<summary><code>process()</code></summary>

#### `RoleMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/RoleMiddleware.php#L46)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

Passes the request through if the current user's role is in the allowed list.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$handler` | `RequestHandlerInterface` | — |

**Returns:** `ResponseInterface`

</details>

## `SessionMiddleware`

*class* — `rafalmasiarek\DashboardKit\Middleware\SessionMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/SessionMiddleware.php#L20)

Ensures PHP session is started for every request. Must run before CsrfMiddleware so that $_SESSION is available when CSRF tokens are validated or generated.

implements `MiddlewareInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`process()`](#sessionmiddleware-process) | public | `ResponseInterface` | Starts the PHP session if not already active. |

<details>
<summary><code>process()</code></summary>

#### `SessionMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Middleware/SessionMiddleware.php#L29)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

Starts the PHP session if not already active.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | Incoming request. |
| `$handler` | `RequestHandlerInterface` | Next middleware in chain. |

**Returns:** `ResponseInterface`

</details>

## `Collection`

*final class* — `rafalmasiarek\DashboardKit\Model\Collection` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L20)

Typed iterable wrapper for a list of Model instances or arbitrary values. Returned by Model::all(), Model::where()->get(), and relation methods. Implements standard PHP collection interfaces so it works in foreach, count(), and array-access contexts without casting to a plain array.

implements `\Countable`, `\IteratorAggregate`, `\ArrayAccess`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#collection-__construct) | public | — | — |
| [`count()`](#collection-count) | public | `int` | Returns the number of items in the collection. |
| [`getIterator()`](#collection-getiterator) | public | `\ArrayIterator<int, T>` | Returns an iterator over the items. |
| [`offsetExists()`](#collection-offsetexists) | public | `bool` | — |
| [`offsetGet()`](#collection-offsetget) | public | `T` | — |
| [`offsetSet()`](#collection-offsetset) | public | `void` | — |
| [`offsetUnset()`](#collection-offsetunset) | public | `void` | — |
| [`first()`](#collection-first) | public | `T\|null` | Returns the first item, or null when the collection is empty. |
| [`last()`](#collection-last) | public | `T\|null` | Returns the last item, or null when the collection is empty. |
| [`isEmpty()`](#collection-isempty) | public | `bool` | Returns true when the collection contains no items. |
| [`toArray()`](#collection-toarray) | public | `list<mixed>` | Converts every Model item to an associative array; passes other values through. |
| [`filter()`](#collection-filter) | public | `static` | Returns a new collection containing only items for which the callback returns true. The resulting list is re-indexed starting from 0. |
| [`map()`](#collection-map) | public | `static` | Applies the callback to every item and returns a new collection of the results. |
| [`pluck()`](#collection-pluck) | public | `list<mixed>` | Extracts a single attribute from each item. Calls ->get($key) on Model instances; reads $item[$key] from arrays. |

<details>
<summary><code>__construct()</code></summary>

#### `Collection __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L25)

```php
public function __construct(array $items)
```

| Param | Type | Description |
|---|---|---|
| `$items` | `list<T>` | Items in this collection. |

</details>

<details>
<summary><code>count()</code></summary>

#### `Collection count()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L32)

```php
public function count(): int
```

Returns the number of items in the collection.

**Returns:** `int`

</details>

<details>
<summary><code>getIterator()</code></summary>

#### `Collection getIterator()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L42)

```php
public function getIterator(): \ArrayIterator
```

Returns an iterator over the items.

**Returns:** `\ArrayIterator<int, T>`

</details>

<details>
<summary><code>offsetExists()</code></summary>

#### `Collection offsetExists()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L51)

```php
public function offsetExists(mixed $offset): bool
```

| Param | Type | Description |
|---|---|---|
| `$offset` | `int` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>offsetGet()</code></summary>

#### `Collection offsetGet()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L60)

```php
public function offsetGet(mixed $offset): mixed
```

| Param | Type | Description |
|---|---|---|
| `$offset` | `int` | — |

**Returns:** `T`

</details>

<details>
<summary><code>offsetSet()</code></summary>

#### `Collection offsetSet()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L70)

```php
public function offsetSet(mixed $offset, mixed $value): void
```

| Param | Type | Description |
|---|---|---|
| `$offset` | `int\|null` | — |
| `$value` | `T` | — |

</details>

<details>
<summary><code>offsetUnset()</code></summary>

#### `Collection offsetUnset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L83)

```php
public function offsetUnset(mixed $offset): void
```

| Param | Type | Description |
|---|---|---|
| `$offset` | `int` | — |

</details>

<details>
<summary><code>first()</code></summary>

#### `Collection first()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L93)

```php
public function first(): mixed
```

Returns the first item, or null when the collection is empty.

**Returns:** `T\|null`

</details>

<details>
<summary><code>last()</code></summary>

#### `Collection last()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L103)

```php
public function last(): mixed
```

Returns the last item, or null when the collection is empty.

**Returns:** `T\|null`

</details>

<details>
<summary><code>isEmpty()</code></summary>

#### `Collection isEmpty()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L113)

```php
public function isEmpty(): bool
```

Returns true when the collection contains no items.

**Returns:** `bool`

</details>

<details>
<summary><code>toArray()</code></summary>

#### `Collection toArray()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L123)

```php
public function toArray(): array
```

Converts every Model item to an associative array; passes other values through.

**Returns:** `list<mixed>`

</details>

<details>
<summary><code>filter()</code></summary>

#### `Collection filter()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L139)

```php
public function filter(callable $callback): static
```

Returns a new collection containing only items for which the callback returns true. The resulting list is re-indexed starting from 0.

| Param | Type | Description |
|---|---|---|
| `$callback` | `callable` | — |

**Returns:** `static`

</details>

<details>
<summary><code>map()</code></summary>

#### `Collection map()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L150)

```php
public function map(callable $callback): static
```

Applies the callback to every item and returns a new collection of the results.

| Param | Type | Description |
|---|---|---|
| `$callback` | `callable` | — |

**Returns:** `static`

</details>

<details>
<summary><code>pluck()</code></summary>

#### `Collection pluck()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Collection.php#L163)

```php
public function pluck(string $key): array
```

Extracts a single attribute from each item. Calls ->get($key) on Model instances; reads $item[$key] from arrays.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Attribute or array key to extract. |

**Returns:** `list<mixed>`

</details>

## `Model`

*abstract class* — `rafalmasiarek\DashboardKit\Model\Model` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L34)

Abstract base class for all application database models. Wire the PDO connection once at boot time via setConnectionResolver() — typically called inside Dashboard::create() so models automatically use CachingPdo and benefit from transparent SELECT caching. Each concrete model must declare at minimum: protected static string $table = 'my_table'; Type casting is declared via: protected static array $casts = ['active' => 'bool', 'created_at' => 'datetime']; Supported cast types: int, float, bool, string, datetime (→ DateTimeImmutable), json, array (both decode JSON to associative array). Soft delete is not GDPR/RODO erasure — the row still exists physically. Use forceDelete() for real erasure, or anonymize columns if the row must be kept. For retention-based cleanup of trashed rows, see prunable()/prune().

**Depends on:** `\Closure`, `PDO`, [`ClockInterface`](#clockinterface)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$table` | `string` | protected static | Database table name. Must be overridden in every concrete model. |
| `$primaryKey` | `string` | protected static | Primary key column name. |
| `$casts` | `array<string, 'int'\|'float'\|'bool'\|'string'\|'datetime'\|'json'\|'array'>` | protected static | Column type casts applied when reading attribute values. |
| `$timestamps` | `bool` | protected static | Whether save() maintains created_at/updated_at automatically. On by default. |
| `$createdAtColumn` | `string` | protected static | Column written on insert and on every subsequent save(). |
| `$updatedAtColumn` | `string` | protected static | Column written on every save(), including insert. |
| `$softDeletes` | `bool` | protected static | Whether delete() soft-deletes (sets deletedAtColumn) instead of removing the row. Off by default. |
| `$deletedAtColumn` | `string` | protected static | Column set by a soft delete and checked by find()/all()/where() to hide trashed rows. |
| `$connectionResolver` | `\Closure\|null` | private static | Lazy connection factory. Called once; result is cached in $resolved. |
| `$resolved` | `PDO\|null` | private static | Resolved PDO instance cached after the first db() call. |
| `$clock` | `ClockInterface\|null` | private static | Clock used for created_at/updated_at and any other "now" computed by Model/QueryBuilder. Defaults to SystemClock (plain system time) until an app calls setClock() — e.g. to wire in its own timezone-aware clock. |
| `$attributes` | `array<string, mixed>` | protected | Raw attribute values as returned by the database driver. |
| `$exists` | `bool` | protected | Whether this instance corresponds to a persisted database row. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`setConnectionResolver()`](#model-setconnectionresolver) | public static | `void` | Registers the PDO factory used by all model classes. Pass a closure rather than the PDO instance directly so that the connection is not resolved until the first model query — important when Dashboard::create() wires models before the container is fully warm. |
| [`setClock()`](#model-setclock) | public static | `void` | Registers the clock used by Model/QueryBuilder for "now" — e.g. an app's own timezone-aware clock, overriding the plain-system-time default. |
| [`getClock()`](#model-getclock) | public static | [`ClockInterface`](#clockinterface) | Returns the active clock, defaulting to SystemClock when setClock() was never called. Public so code outside the Model hierarchy (e.g. an addon repository with no DI of its own) can read "now" from the same single source as Model/QueryBuilder. |
| [`getTable()`](#model-gettable) | public static | `string` | Returns the logical table name declared by the concrete model. Used by QueryBuilder to build SQL without accessing protected statics directly. |
| [`db()`](#model-db) | protected static | `PDO` | Returns the active PDO connection, resolving it on the first call. |
| [`fromRow()`](#model-fromrow) | public static | `static` | Creates a model instance from a raw database row without triggering a query. |
| [`find()`](#model-find) | public static | `static\|null` | Finds a single model by primary key. Hidden when soft-deleted — use withTrashed() to look up a possibly-trashed row by id. |
| [`all()`](#model-all) | public static | [`Collection`](#collection) | Returns all non-trashed rows in the table as a Collection of model instances. |
| [`where()`](#model-where) | public static | [`QueryBuilder`](#querybuilder) | Starts a fluent WHERE chain for the model's table. When called with two arguments, '=' is assumed as the operator: Model::where('active', 1) → WHERE `active` = ? Model::where('role', '!=', 'user') → WHERE `role` != ? |
| [`withTrashed()`](#model-withtrashed) | public static | [`QueryBuilder`](#querybuilder) | Starts a query that also matches soft-deleted rows. |
| [`onlyTrashed()`](#model-onlytrashed) | public static | [`QueryBuilder`](#querybuilder) | Starts a query that matches only soft-deleted rows. |
| [`usesSoftDeletes()`](#model-usessoftdeletes) | public static | `bool` | Whether this model soft-deletes instead of removing rows outright. |
| [`getDeletedAtColumn()`](#model-getdeletedatcolumn) | public static | `string` | Column name soft-deletes are written to. |
| [`newQuery()`](#model-newquery) | protected static | [`QueryBuilder`](#querybuilder) | Builds a fresh, unconditioned query for this model's table. |
| [`on()`](#model-on) | public static | [`QueryBuilder`](#querybuilder) | Starts a query against an arbitrary table with no dedicated model class — for tables whose name is only known at runtime (e.g. one table per admin-defined content type). Rows come back as plain arrays, not model instances, and soft deletes are not applied. |
| [`whereIn()`](#model-wherein) | public static | [`QueryBuilder`](#querybuilder) | Starts a fluent WHERE column IN (...) chain for the model's table. |
| [`whereNotIn()`](#model-wherenotin) | public static | [`QueryBuilder`](#querybuilder) | Starts a fluent WHERE column NOT IN (...) chain for the model's table. |
| [`whereRaw()`](#model-whereraw) | public static | [`QueryBuilder`](#querybuilder) | Starts a fluent WHERE chain for the model's table with a raw SQL fragment as the first condition — see QueryBuilder::whereRaw(). |
| [`transaction()`](#model-transaction) | public static | `T` | Runs $callback inside a transaction, committing on normal return and rolling back if it throws. |
| [`upsert()`](#model-upsert) | public static | `bool` | Inserts a row, or updates it in one atomic statement when a row already matches $uniqueBy (MySQL: ON DUPLICATE KEY UPDATE; SQLite: ON CONFLICT DO UPDATE) — safe under concurrent writers, unlike a SELECT-then-branch. createdAtColumn is never touched on the update branch. |
| [`insertOrIgnore()`](#model-insertorignore) | public static | `bool` | Inserts a row, silently doing nothing if it would violate a unique constraint (MySQL: INSERT IGNORE; SQLite: INSERT OR IGNORE; PostgreSQL: ON CONFLICT DO NOTHING) — idempotent insert, not an upsert; an existing row is never modified. |
| [`now()`](#model-now) | protected static | `string` | Current timestamp in the format written to created_at/updated_at columns. |
| [`create()`](#model-create) | public static | `static` | Inserts a new row and returns the resulting model instance. |
| [`__get()`](#model-__get) | public | `mixed` | Reads an attribute value with type casting applied. |
| [`__set()`](#model-__set) | public | `void` | Sets an attribute value. |
| [`__isset()`](#model-__isset) | public | `bool` | Returns true when the attribute exists in the current row (even if null). |
| [`get()`](#model-get) | public | `mixed` | Returns the cast value for a given attribute key. |
| [`set()`](#model-set) | public | `void` | Stores a raw value for the given attribute key. |
| [`fill()`](#model-fill) | public | `static` | Sets multiple attributes at once and returns $this for chaining. |
| [`toArray()`](#model-toarray) | public | `array<string, mixed>` | Returns all attributes as an associative array with casts applied. |
| [`save()`](#model-save) | public | `bool` | Persists the model to the database. Issues INSERT when $exists is false; UPDATE when $exists is true. On successful INSERT, sets $exists = true and stores the last-insert-id when no primary-key value was provided in attributes. When $timestamps is enabled, createdAtColumn is set on insert (unless already present) and updatedAtColumn is set on every save. |
| [`delete()`](#model-delete) | public | `bool` | Removes the row. Soft-deletes (sets deletedAtColumn, keeps the row) when $softDeletes is enabled; otherwise deletes it outright. |
| [`forceDelete()`](#model-forcedelete) | public | `bool` | Deletes the row outright, bypassing soft deletes. |
| [`restore()`](#model-restore) | public | `bool` | Clears a soft delete, making the row visible to normal queries again. |
| [`trashed()`](#model-trashed) | public | `bool` | Whether this instance is currently soft-deleted. |
| [`prunable()`](#model-prunable) | protected static | `QueryBuilder\|null` | Query selecting rows eligible for prune(). Null = pruning disabled. Override per model, e.g. return static::trashedOlderThan('-30 days'). |
| [`pruning()`](#model-pruning) | protected | `void` | Called on each row before prune() force-deletes it. Override to clean up owned resources (files, cache, ...). |
| [`prune()`](#model-prune) | public static | `int` | Force-deletes every row matched by prunable(), chunked, calling pruning() on each first. Call from a scheduled task, not per-request. |
| [`trashedOlderThan()`](#model-trashedolderthan) | protected static | [`QueryBuilder`](#querybuilder) | Soft-deleted rows older than $interval (DateTime::modify() format, e.g. '-30 days'). |
| [`cast()`](#model-cast) | protected | `mixed` | Applies the declared cast for a column to its raw DB value. |
| [`encodeForPersistence()`](#model-encodeforpersistence) | private | `array<string, mixed>` | Attributes ready to bind to a PDO statement: json/array-cast columns holding a non-string value (array/object) are json_encode()'d; everything else passes through unchanged. Does not mutate $this->attributes. |
| [`hasMany()`](#model-hasmany) | protected | [`Collection`](#collection) | Loads all related models where $foreignKey on the related table equals this model's $localKey. |
| [`belongsTo()`](#model-belongsto) | protected | `object\|null` | Loads the single related model whose $ownerKey matches this model's $foreignKey value. |

<details>
<summary><code>setConnectionResolver()</code></summary>

#### `Model setConnectionResolver()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L140)

```php
public static function setConnectionResolver(\Closure $resolver): void
```

Registers the PDO factory used by all model classes. Pass a closure rather than the PDO instance directly so that the connection is not resolved until the first model query — important when Dashboard::create() wires models before the container is fully warm.

| Param | Type | Description |
|---|---|---|
| `$resolver` | `\Closure` | — |

</details>

<details>
<summary><code>setClock()</code></summary>

#### `Model setClock()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L153)

```php
public static function setClock(ClockInterface $clock): void
```

Registers the clock used by Model/QueryBuilder for "now" — e.g. an app's own timezone-aware clock, overriding the plain-system-time default.

| Param | Type | Description |
|---|---|---|
| `$clock` | [`ClockInterface`](#clockinterface) | — |

</details>

<details>
<summary><code>getClock()</code></summary>

#### `Model getClock()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L166)

```php
public static function getClock(): ClockInterface
```

Returns the active clock, defaulting to SystemClock when setClock() was never called. Public so code outside the Model hierarchy (e.g. an addon repository with no DI of its own) can read "now" from the same single source as Model/QueryBuilder.

**Returns:** [`ClockInterface`](#clockinterface)

</details>

<details>
<summary><code>getTable()</code></summary>

#### `Model getTable()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L178)

```php
public static function getTable(): string
```

Returns the logical table name declared by the concrete model. Used by QueryBuilder to build SQL without accessing protected statics directly.

**Returns:** `string`

</details>

<details>
<summary><code>db()</code></summary>

#### `Model db()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L190)

```php
protected static function db(): PDO
```

Returns the active PDO connection, resolving it on the first call.

**Returns:** `PDO`

| Throws | When |
|---|---|
| `\RuntimeException` | When no resolver has been registered. |

</details>

<details>
<summary><code>fromRow()</code></summary>

#### `Model fromRow()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L210)

```php
public static function fromRow(array $row): static
```

Creates a model instance from a raw database row without triggering a query.

| Param | Type | Description |
|---|---|---|
| `$row` | `array<string, mixed>` | Column-value map from PDO::FETCH_ASSOC. |

**Returns:** `static`

</details>

<details>
<summary><code>find()</code></summary>

#### `Model find()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L225)

```php
public static function find(int|string $id): ?static
```

Finds a single model by primary key. Hidden when soft-deleted — use withTrashed() to look up a possibly-trashed row by id.

| Param | Type | Description |
|---|---|---|
| `$id` | `int\|string` | Primary key value. |

**Returns:** `static\|null` — Null when no matching (non-trashed) row exists.

</details>

<details>
<summary><code>all()</code></summary>

#### `Model all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L236)

```php
public static function all(): Collection
```

Returns all non-trashed rows in the table as a Collection of model instances.

**Returns:** [`Collection`](#collection)

</details>

<details>
<summary><code>where()</code></summary>

#### `Model where()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L253)

```php
public static function where(string $column, mixed $operatorOrValue, mixed $value): QueryBuilder
```

Starts a fluent WHERE chain for the model's table. When called with two arguments, '=' is assumed as the operator: Model::where('active', 1)        → WHERE `active` = ? Model::where('role', '!=', 'user') → WHERE `role` != ?

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (not user input). |
| `$operatorOrValue` | `mixed` | Operator string when $value is provided; bound value otherwise. |
| `$value` | `mixed` | Bound value when an explicit operator is given. |

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>withTrashed()</code></summary>

#### `Model withTrashed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L265)

```php
public static function withTrashed(): QueryBuilder
```

Starts a query that also matches soft-deleted rows.

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>onlyTrashed()</code></summary>

#### `Model onlyTrashed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L275)

```php
public static function onlyTrashed(): QueryBuilder
```

Starts a query that matches only soft-deleted rows.

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>usesSoftDeletes()</code></summary>

#### `Model usesSoftDeletes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L285)

```php
public static function usesSoftDeletes(): bool
```

Whether this model soft-deletes instead of removing rows outright.

**Returns:** `bool`

</details>

<details>
<summary><code>getDeletedAtColumn()</code></summary>

#### `Model getDeletedAtColumn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L295)

```php
public static function getDeletedAtColumn(): string
```

Column name soft-deletes are written to.

**Returns:** `string`

</details>

<details>
<summary><code>newQuery()</code></summary>

#### `Model newQuery()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L305)

```php
protected static function newQuery(): QueryBuilder
```

Builds a fresh, unconditioned query for this model's table.

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>on()</code></summary>

#### `Model on()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L319)

```php
public static function on(string $table): QueryBuilder
```

Starts a query against an arbitrary table with no dedicated model class — for tables whose name is only known at runtime (e.g. one table per admin-defined content type). Rows come back as plain arrays, not model instances, and soft deletes are not applied.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | — |

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>whereIn()</code></summary>

#### `Model whereIn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L331)

```php
public static function whereIn(string $column, array $values): QueryBuilder
```

Starts a fluent WHERE column IN (...) chain for the model's table.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (not user input). |
| `$values` | `list<mixed>` | — |

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>whereNotIn()</code></summary>

#### `Model whereNotIn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L343)

```php
public static function whereNotIn(string $column, array $values): QueryBuilder
```

Starts a fluent WHERE column NOT IN (...) chain for the model's table.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (not user input). |
| `$values` | `list<mixed>` | — |

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>whereRaw()</code></summary>

#### `Model whereRaw()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L356)

```php
public static function whereRaw(string $sql, array $params): QueryBuilder
```

Starts a fluent WHERE chain for the model's table with a raw SQL fragment as the first condition — see QueryBuilder::whereRaw().

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | Raw SQL fragment. |
| `$params` | `list<mixed>` | Bound values for the '?' placeholders in $sql, in order. |

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>transaction()</code></summary>

#### `Model transaction()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L369)

```php
public static function transaction(callable $callback): mixed
```

Runs $callback inside a transaction, committing on normal return and rolling back if it throws.

| Param | Type | Description |
|---|---|---|
| `$callback` | `callable` | — |

**Returns:** `T`

</details>

<details>
<summary><code>upsert()</code></summary>

#### `Model upsert()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L395)

```php
public static function upsert(array $attributes, array $uniqueBy, ?array $updateColumns): bool
```

Inserts a row, or updates it in one atomic statement when a row already matches $uniqueBy (MySQL: ON DUPLICATE KEY UPDATE; SQLite: ON CONFLICT DO UPDATE) — safe under concurrent writers, unlike a SELECT-then-branch. createdAtColumn is never touched on the update branch.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | Column-value pairs to insert. |
| `$uniqueBy` | `list<string>` | Columns identifying an existing row (unique/PK). |
| `$updateColumns` | `list<string>\|null` | Columns to refresh on conflict. Defaults to every column except $uniqueBy and createdAtColumn. |

**Returns:** `bool`

</details>

<details>
<summary><code>insertOrIgnore()</code></summary>

#### `Model insertOrIgnore()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L436)

```php
public static function insertOrIgnore(array $attributes): bool
```

Inserts a row, silently doing nothing if it would violate a unique constraint (MySQL: INSERT IGNORE; SQLite: INSERT OR IGNORE; PostgreSQL: ON CONFLICT DO NOTHING) — idempotent insert, not an upsert; an existing row is never modified.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | Column-value pairs to insert. |

**Returns:** `bool`

</details>

<details>
<summary><code>now()</code></summary>

#### `Model now()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L466)

```php
protected static function now(): string
```

Current timestamp in the format written to created_at/updated_at columns.

**Returns:** `string`

</details>

<details>
<summary><code>create()</code></summary>

#### `Model create()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L477)

```php
public static function create(array $attributes): static
```

Inserts a new row and returns the resulting model instance.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | Column-value pairs to insert. |

**Returns:** `static`

</details>

<details>
<summary><code>__get()</code></summary>

#### `Model __get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L491)

```php
public function __get(string $name): mixed
```

Reads an attribute value with type casting applied.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>__set()</code></summary>

#### `Model __set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L503)

```php
public function __set(string $name, mixed $value): void
```

Sets an attribute value.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$value` | `mixed` | — |

</details>

<details>
<summary><code>__isset()</code></summary>

#### `Model __isset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L514)

```php
public function __isset(string $name): bool
```

Returns true when the attribute exists in the current row (even if null).

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>get()</code></summary>

#### `Model get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L525)

```php
public function get(string $key): mixed
```

Returns the cast value for a given attribute key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

**Returns:** `mixed` — Null when the key does not exist in attributes.

</details>

<details>
<summary><code>set()</code></summary>

#### `Model set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L540)

```php
public function set(string $key, mixed $value): void
```

Stores a raw value for the given attribute key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$value` | `mixed` | — |

</details>

<details>
<summary><code>fill()</code></summary>

#### `Model fill()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L551)

```php
public function fill(array $attributes): static
```

Sets multiple attributes at once and returns $this for chaining.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | — |

**Returns:** `static`

</details>

<details>
<summary><code>toArray()</code></summary>

#### `Model toArray()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L564)

```php
public function toArray(): array
```

Returns all attributes as an associative array with casts applied.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>save()</code></summary>

#### `Model save()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L584)

```php
public function save(): bool
```

Persists the model to the database. Issues INSERT when $exists is false; UPDATE when $exists is true. On successful INSERT, sets $exists = true and stores the last-insert-id when no primary-key value was provided in attributes. When $timestamps is enabled, createdAtColumn is set on insert (unless already present) and updatedAtColumn is set on every save.

**Returns:** `bool` — True on success.

</details>

<details>
<summary><code>delete()</code></summary>

#### `Model delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L630)

```php
public function delete(): bool
```

Removes the row. Soft-deletes (sets deletedAtColumn, keeps the row) when $softDeletes is enabled; otherwise deletes it outright.

**Returns:** `bool` — False when the instance has not been persisted yet.

</details>

<details>
<summary><code>forceDelete()</code></summary>

#### `Model forceDelete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L649)

```php
public function forceDelete(): bool
```

Deletes the row outright, bypassing soft deletes.

**Returns:** `bool` — False when the instance has not been persisted yet.

</details>

<details>
<summary><code>restore()</code></summary>

#### `Model restore()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L672)

```php
public function restore(): bool
```

Clears a soft delete, making the row visible to normal queries again.

**Returns:** `bool` — False when this model does not use soft deletes.

</details>

<details>
<summary><code>trashed()</code></summary>

#### `Model trashed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L687)

```php
public function trashed(): bool
```

Whether this instance is currently soft-deleted.

**Returns:** `bool`

</details>

<details>
<summary><code>prunable()</code></summary>

#### `Model prunable()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L698)

```php
protected static function prunable(): ?QueryBuilder
```

Query selecting rows eligible for prune(). Null = pruning disabled. Override per model, e.g. return static::trashedOlderThan('-30 days').

**Returns:** `QueryBuilder\|null`

</details>

<details>
<summary><code>pruning()</code></summary>

#### `Model pruning()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L709)

```php
protected function pruning(): void
```

Called on each row before prune() force-deletes it. Override to clean up owned resources (files, cache, ...).

</details>

<details>
<summary><code>prune()</code></summary>

#### `Model prune()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L720)

```php
public static function prune(int $chunkSize): int
```

Force-deletes every row matched by prunable(), chunked, calling pruning() on each first. Call from a scheduled task, not per-request.

| Param | Type | Description |
|---|---|---|
| `$chunkSize` | `int` | Rows fetched and removed per batch. |

**Returns:** `int` — Number of rows pruned.

</details>

<details>
<summary><code>trashedOlderThan()</code></summary>

#### `Model trashedOlderThan()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L750)

```php
protected static function trashedOlderThan(string $interval): QueryBuilder
```

Soft-deleted rows older than $interval (DateTime::modify() format, e.g. '-30 days').

| Param | Type | Description |
|---|---|---|
| `$interval` | `string` | — |

**Returns:** [`QueryBuilder`](#querybuilder)

</details>

<details>
<summary><code>cast()</code></summary>

#### `Model cast()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L763)

```php
protected function cast(string $key, mixed $value): mixed
```

Applies the declared cast for a column to its raw DB value.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | Column name. |
| `$value` | `mixed` | Raw value from PDO. |

**Returns:** `mixed` — Typed value, or the raw value when no cast is declared.

</details>

<details>
<summary><code>encodeForPersistence()</code></summary>

#### `Model encodeForPersistence()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L787)

```php
private function encodeForPersistence(): array
```

Attributes ready to bind to a PDO statement: json/array-cast columns holding a non-string value (array/object) are json_encode()'d; everything else passes through unchanged. Does not mutate $this->attributes.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>hasMany()</code></summary>

#### `Model hasMany()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L809)

```php
protected function hasMany(string $related, string $foreignKey, string $localKey): Collection
```

Loads all related models where $foreignKey on the related table equals this model's $localKey.

| Param | Type | Description |
|---|---|---|
| `$related` | `class-string<Model>` | Fully-qualified related model class. |
| `$foreignKey` | `string` | Column on the related table pointing back to this model. |
| `$localKey` | `string` | Column on this model used as the local identifier. Defaults to primary key. |

**Returns:** [`Collection`](#collection)

</details>

<details>
<summary><code>belongsTo()</code></summary>

#### `Model belongsTo()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Model.php#L828)

```php
protected function belongsTo(string $related, string $foreignKey, string $ownerKey): ?object
```

Loads the single related model whose $ownerKey matches this model's $foreignKey value.

| Param | Type | Description |
|---|---|---|
| `$related` | `class-string<Model>` | Fully-qualified related model class. |
| `$foreignKey` | `string` | Column on this model holding the foreign key value. |
| `$ownerKey` | `string` | Column on the related table to match against. Defaults to related primary key. |

**Returns:** `object\|null`

</details>

## `QueryBuilder`

*final class* — `rafalmasiarek\DashboardKit\Model\QueryBuilder` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L20)

Fluent SELECT query builder for a model class or an ad-hoc table. Created by Model::where()/Model::on() and returned for method chaining. Column and table names are interpolated directly into SQL — callers must not pass user-supplied identifiers. Values are always bound as prepared-statement parameters, except Raw (emitted verbatim).

**Depends on:** `PDO`, [`ClockInterface`](#clockinterface)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$wheres` | `list<array{column: string, operator: string, value: mixed}\|array{column: string, in: list<mixed>, not: bool}\|array{raw: string, params: list<mixed>}>` | private | WHERE conditions accumulated via where()/whereIn()/whereRaw() calls. |
| `$joins` | `list<string>` | private | JOIN clauses accumulated via join()/leftJoin(). |
| `$limitVal` | `int\|null` | private | LIMIT value; null = no limit. |
| `$offsetVal` | `int\|null` | private | OFFSET value; null = no offset. |
| `$orderBys` | `list<string>` | private | ORDER BY fragments, e.g. ['`name` ASC', '`created_at` DESC']. |
| `$selectColumns` | `list<string>` | private | SELECT column list. Defaults to '*'. Set via select() — e.g. for a join() where some joined table's columns should be left out. |
| `$includeTrashed` | `bool` | private | Whether soft-deleted rows are included. Set by withTrashed()/onlyTrashed(). |
| `$onlyTrashed` | `bool` | private | Whether only soft-deleted rows should match. Set by onlyTrashed(). |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#querybuilder-__construct) | public | — | — |
| [`withTrashed()`](#querybuilder-withtrashed) | public | `static` | Includes soft-deleted rows in the result, alongside normal ones. |
| [`onlyTrashed()`](#querybuilder-onlytrashed) | public | `static` | Restricts the result to soft-deleted rows only. |
| [`where()`](#querybuilder-where) | public | `static` | Adds a WHERE condition. Multiple calls are combined with AND. When called with two arguments, '=' is assumed as the operator: ->where('active', 1) → WHERE `active` = ? ->where('role', '!=', 'user') → WHERE `role` != ? A null value renders IS NULL / IS NOT NULL instead of binding a parameter. A Raw value is emitted verbatim instead of being bound: ->where('expires_at', '>', new Raw('NOW()')) $column is backtick-wrapped as one identifier — do not pass a qualified 'table.column' name here (use select()/join() for those). |
| [`whereIn()`](#querybuilder-wherein) | public | `static` | Adds a WHERE column IN (...) condition. |
| [`whereNotIn()`](#querybuilder-wherenotin) | public | `static` | Adds a WHERE column NOT IN (...) condition. |
| [`whereRaw()`](#querybuilder-whereraw) | public | `static` | Adds a raw WHERE fragment, ANDed with every other condition — an escape hatch for boolean logic where() can't express, e.g. an OR branch or an EXISTS(...) subquery: ->whereRaw('(created_by = ? OR EXISTS (SELECT 1 FROM t WHERE t.x = ?))', [$a, $b]) $sql is emitted verbatim (trusted, not user input) — wrap it in parentheses yourself when it contains OR, so it combines correctly with the implicit AND joining every WHERE condition. |
| [`select()`](#querybuilder-select) | public | `static` | Sets the SELECT column list, replacing the default '*'. Columns are emitted verbatim (trusted, not user input) — qualify them yourself for a join, e.g. select('user_tokens.token', 'users.email'). |
| [`join()`](#querybuilder-join) | public | `static` | Adds an INNER JOIN clause. |
| [`leftJoin()`](#querybuilder-leftjoin) | public | `static` | Adds a LEFT JOIN clause. |
| [`joinOn()`](#querybuilder-joinon) | public | `static` | Adds an INNER JOIN with an arbitrary raw ON clause — for a multi-column condition join() can't express in one (first, operator, second) triple: ->joinOn('esign_guest_invites', 'esign_guest_invites.envelope_id = esign_signers.envelope_id ' . 'AND esign_guest_invites.signer_id = esign_signers.signer_id') $onSql is emitted verbatim (trusted, not user input) — it compares columns to columns, not to bound values; put value comparisons in where()/whereRaw() instead. |
| [`leftJoinOn()`](#querybuilder-leftjoinon) | public | `static` | Adds a LEFT JOIN with an arbitrary raw ON clause — see joinOn(). |
| [`insert()`](#querybuilder-insert) | public | `string` | Inserts a row into this builder's table. The only way to insert when built from Model::on() — there is no model class to construct and save(). Ignores any accumulated WHERE/JOIN/etc.; those only apply to get()/ first()/count()/update()/delete(). |
| [`upsert()`](#querybuilder-upsert) | public | `bool` | Inserts a row, or updates it in one atomic statement when a row already matches $uniqueBy (MySQL: ON DUPLICATE KEY UPDATE; SQLite: ON CONFLICT DO UPDATE) — safe under concurrent writers, unlike a SELECT-then-branch. Unlike Model::upsert(), does not auto-manage timestamps — this is the ad-hoc-table path, which has no createdAtColumn/updatedAtColumn to know about. |
| [`insertOrIgnore()`](#querybuilder-insertorignore) | public | `bool` | Inserts a row, silently doing nothing if it would violate a unique constraint (MySQL: INSERT IGNORE; SQLite: INSERT OR IGNORE; PostgreSQL: ON CONFLICT DO NOTHING) — idempotent insert, not an upsert; an existing row is never modified. |
| [`limit()`](#querybuilder-limit) | public | `static` | Sets the maximum number of rows to return. |
| [`offset()`](#querybuilder-offset) | public | `static` | Sets the row offset for pagination. |
| [`orderBy()`](#querybuilder-orderby) | public | `static` | Adds an ORDER BY clause. |
| [`get()`](#querybuilder-get) | public | [`Collection`](#collection) | Executes the SELECT. Returns model instances when built from a model class, or plain associative arrays for an ad-hoc table (Model::on()). |
| [`first()`](#querybuilder-first) | public | `object\|array<string, mixed>\|null` | Returns the first matching row, or null when none found. |
| [`count()`](#querybuilder-count) | public | `int` | Returns the total number of matching rows. |
| [`max()`](#querybuilder-max) | public | `int\|float\|string\|null` | Returns the maximum value of $column across matching rows, or null when no row matches (SQL MAX() of an empty set). |
| [`min()`](#querybuilder-min) | public | `int\|float\|string\|null` | Returns the minimum value of $column across matching rows, or null when no row matches (SQL MIN() of an empty set). |
| [`sum()`](#querybuilder-sum) | public | `int\|float` | Returns the sum of $column across matching rows. SQL SUM() of an empty set is NULL; normalized to 0 since a caller asking for a sum virtually always wants a number, not a null check. |
| [`aggregate()`](#querybuilder-aggregate) | private | `int\|float\|string\|null` | Runs a single-column SQL aggregate (MAX/MIN/SUM) over matching rows. |
| [`delete()`](#querybuilder-delete) | public | `int` | Deletes every matching row in one statement. Soft-deletes (bulk UPDATE of deletedAtColumn) when the model uses soft deletes; otherwise a hard DELETE. Use forceDelete() to always hard-delete regardless. |
| [`forceDelete()`](#querybuilder-forcedelete) | public | `int` | Deletes every matching row outright in one statement, bypassing soft deletes. |
| [`update()`](#querybuilder-update) | public | `int` | Updates every matching row with the given column values in one statement. Does not auto-manage timestamps — include updatedAtColumn in $attributes if it should be refreshed. A Raw value is emitted verbatim instead of bound — e.g. ->update(['attempts' => new Raw('attempts + 1')]) for an atomic, concurrency-safe increment. |
| [`buildSelect()`](#querybuilder-buildselect) | private | `array{sql: string, params: list<mixed>}` | Builds the full SELECT SQL string and parameter list. |
| [`joinSql()`](#querybuilder-joinsql) | private | `string` | Joins accumulated join()/leftJoin() clauses into one SQL fragment. |
| [`buildWhere()`](#querybuilder-buildwhere) | private | `array{string, list<mixed>}` | Builds the WHERE clause string and collects bound values, including the soft-delete filter implied by withTrashed()/onlyTrashed() when the model uses soft deletes. |
| [`renderCondition()`](#querybuilder-rendercondition) | private | `string` | Renders one WHERE condition, appending any bound parameter to $params by reference. |
| [`softDeleteClause()`](#querybuilder-softdeleteclause) | private | `string\|null` | Soft-delete SQL fragment implied by the model and withTrashed()/onlyTrashed(), if any. |

<details>
<summary><code>__construct()</code></summary>

#### `QueryBuilder __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L85)

```php
public function __construct(?string $modelClass, string $table, PDO $pdo, ClockInterface $clock, bool $softDeletes, string $deletedAtColumn)
```

| Param | Type | Description |
|---|---|---|
| `$modelClass` | `string\|null` | Fully-qualified model class, or null for an ad-hoc table (Model::on()) — rows are returned as plain arrays instead of model instances when null. |
| `$table` | `string` | Table name. |
| `$pdo` | `PDO` | Active database connection. |
| `$clock` | [`ClockInterface`](#clockinterface) | Clock used for soft-delete's deleted_at timestamp. |
| `$softDeletes` | `bool` | Whether to filter deleted_at automatically. |
| `$deletedAtColumn` | `string` | Column soft-deletes are written to. |

</details>

<details>
<summary><code>withTrashed()</code></summary>

#### `QueryBuilder withTrashed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L99)

```php
public function withTrashed(): static
```

Includes soft-deleted rows in the result, alongside normal ones.

**Returns:** `static`

</details>

<details>
<summary><code>onlyTrashed()</code></summary>

#### `QueryBuilder onlyTrashed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L110)

```php
public function onlyTrashed(): static
```

Restricts the result to soft-deleted rows only.

**Returns:** `static`

</details>

<details>
<summary><code>where()</code></summary>

#### `QueryBuilder where()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L136)

```php
public function where(string $column, mixed $operatorOrValue, mixed $value): static
```

Adds a WHERE condition. Multiple calls are combined with AND. When called with two arguments, '=' is assumed as the operator: ->where('active', 1)          → WHERE `active` = ? ->where('role', '!=', 'user') → WHERE `role` != ? A null value renders IS NULL / IS NOT NULL instead of binding a parameter. A Raw value is emitted verbatim instead of being bound: ->where('expires_at', '>', new Raw('NOW()')) $column is backtick-wrapped as one identifier — do not pass a qualified 'table.column' name here (use select()/join() for those).

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |
| `$operatorOrValue` | `mixed` | Operator string when $value is given; bound value otherwise. |
| `$value` | `mixed` | Bound value, null, or Raw when an explicit operator is given. |

**Returns:** `static`

</details>

<details>
<summary><code>whereIn()</code></summary>

#### `QueryBuilder whereIn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L153)

```php
public function whereIn(string $column, array $values): static
```

Adds a WHERE column IN (...) condition.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |
| `$values` | `list<mixed>` | Bound values. An empty list matches no rows. |

**Returns:** `static`

</details>

<details>
<summary><code>whereNotIn()</code></summary>

#### `QueryBuilder whereNotIn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L166)

```php
public function whereNotIn(string $column, array $values): static
```

Adds a WHERE column NOT IN (...) condition.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |
| `$values` | `list<mixed>` | Bound values. An empty list matches every row. |

**Returns:** `static`

</details>

<details>
<summary><code>whereRaw()</code></summary>

#### `QueryBuilder whereRaw()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L186)

```php
public function whereRaw(string $sql, array $params): static
```

Adds a raw WHERE fragment, ANDed with every other condition — an escape hatch for boolean logic where() can't express, e.g. an OR branch or an EXISTS(...) subquery: ->whereRaw('(created_by = ? OR EXISTS (SELECT 1 FROM t WHERE t.x = ?))', [$a, $b]) $sql is emitted verbatim (trusted, not user input) — wrap it in parentheses yourself when it contains OR, so it combines correctly with the implicit AND joining every WHERE condition.

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | Raw SQL fragment. |
| `$params` | `list<mixed>` | Bound values for the '?' placeholders in $sql, in order. |

**Returns:** `static`

</details>

<details>
<summary><code>select()</code></summary>

#### `QueryBuilder select()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L200)

```php
public function select(string $columns): static
```

Sets the SELECT column list, replacing the default '*'. Columns are emitted verbatim (trusted, not user input) — qualify them yourself for a join, e.g. select('user_tokens.token', 'users.email').

| Param | Type | Description |
|---|---|---|
| `$columns` | `string` | — |

**Returns:** `static`

</details>

<details>
<summary><code>join()</code></summary>

#### `QueryBuilder join()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L215)

```php
public function join(string $table, string $first, string $operator, string $second): static
```

Adds an INNER JOIN clause.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table to join (trusted, not user input). |
| `$first` | `string` | Column on the already-selected table(s). |
| `$operator` | `string` | Comparison operator, usually '='. |
| `$second` | `string` | Column on $table. |

**Returns:** `static`

</details>

<details>
<summary><code>leftJoin()</code></summary>

#### `QueryBuilder leftJoin()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L230)

```php
public function leftJoin(string $table, string $first, string $operator, string $second): static
```

Adds a LEFT JOIN clause.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table to join (trusted, not user input). |
| `$first` | `string` | Column on the already-selected table(s). |
| `$operator` | `string` | Comparison operator, usually '='. |
| `$second` | `string` | Column on $table. |

**Returns:** `static`

</details>

<details>
<summary><code>joinOn()</code></summary>

#### `QueryBuilder joinOn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L249)

```php
public function joinOn(string $table, string $onSql): static
```

Adds an INNER JOIN with an arbitrary raw ON clause — for a multi-column condition join() can't express in one (first, operator, second) triple: ->joinOn('esign_guest_invites', 'esign_guest_invites.envelope_id = esign_signers.envelope_id ' . 'AND esign_guest_invites.signer_id = esign_signers.signer_id') $onSql is emitted verbatim (trusted, not user input) — it compares columns to columns, not to bound values; put value comparisons in where()/whereRaw() instead.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table to join (trusted, not user input). |
| `$onSql` | `string` | Raw ON clause (trusted, not user input). |

**Returns:** `static`

</details>

<details>
<summary><code>leftJoinOn()</code></summary>

#### `QueryBuilder leftJoinOn()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L262)

```php
public function leftJoinOn(string $table, string $onSql): static
```

Adds a LEFT JOIN with an arbitrary raw ON clause — see joinOn().

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table to join (trusted, not user input). |
| `$onSql` | `string` | Raw ON clause (trusted, not user input). |

**Returns:** `static`

</details>

<details>
<summary><code>insert()</code></summary>

#### `QueryBuilder insert()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L277)

```php
public function insert(array $attributes): string
```

Inserts a row into this builder's table. The only way to insert when built from Model::on() — there is no model class to construct and save(). Ignores any accumulated WHERE/JOIN/etc.; those only apply to get()/ first()/count()/update()/delete().

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | — |

**Returns:** `string` — Last-insert-id, or '' if the driver reports none.

</details>

<details>
<summary><code>upsert()</code></summary>

#### `QueryBuilder upsert()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L299)

```php
public function upsert(array $attributes, array $uniqueBy, ?array $updateColumns): bool
```

Inserts a row, or updates it in one atomic statement when a row already matches $uniqueBy (MySQL: ON DUPLICATE KEY UPDATE; SQLite: ON CONFLICT DO UPDATE) — safe under concurrent writers, unlike a SELECT-then-branch. Unlike Model::upsert(), does not auto-manage timestamps — this is the ad-hoc-table path, which has no createdAtColumn/updatedAtColumn to know about.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | — |
| `$uniqueBy` | `list<string>` | Columns identifying an existing row (unique/PK). |
| `$updateColumns` | `list<string>\|null` | Columns to refresh on conflict. Defaults to every column except $uniqueBy. |

**Returns:** `bool`

</details>

<details>
<summary><code>insertOrIgnore()</code></summary>

#### `QueryBuilder insertOrIgnore()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L333)

```php
public function insertOrIgnore(array $attributes): bool
```

Inserts a row, silently doing nothing if it would violate a unique constraint (MySQL: INSERT IGNORE; SQLite: INSERT OR IGNORE; PostgreSQL: ON CONFLICT DO NOTHING) — idempotent insert, not an upsert; an existing row is never modified.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>limit()</code></summary>

#### `QueryBuilder limit()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L356)

```php
public function limit(int $limit): static
```

Sets the maximum number of rows to return.

| Param | Type | Description |
|---|---|---|
| `$limit` | `int` | — |

**Returns:** `static`

</details>

<details>
<summary><code>offset()</code></summary>

#### `QueryBuilder offset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L368)

```php
public function offset(int $offset): static
```

Sets the row offset for pagination.

| Param | Type | Description |
|---|---|---|
| `$offset` | `int` | — |

**Returns:** `static`

</details>

<details>
<summary><code>orderBy()</code></summary>

#### `QueryBuilder orderBy()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L381)

```php
public function orderBy(string $column, string $direction): static
```

Adds an ORDER BY clause.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |
| `$direction` | `string` | 'ASC' or 'DESC'. Defaults to 'ASC'. |

**Returns:** `static`

</details>

<details>
<summary><code>get()</code></summary>

#### `QueryBuilder get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L393)

```php
public function get(): Collection
```

Executes the SELECT. Returns model instances when built from a model class, or plain associative arrays for an ad-hoc table (Model::on()).

**Returns:** [`Collection`](#collection)

</details>

<details>
<summary><code>first()</code></summary>

#### `QueryBuilder first()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L413)

```php
public function first(): object|array|null
```

Returns the first matching row, or null when none found.

**Returns:** `object\|array<string, mixed>\|null`

</details>

<details>
<summary><code>count()</code></summary>

#### `QueryBuilder count()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L424)

```php
public function count(): int
```

Returns the total number of matching rows.

**Returns:** `int`

</details>

<details>
<summary><code>max()</code></summary>

#### `QueryBuilder max()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L441)

```php
public function max(string $column): int|float|string|null
```

Returns the maximum value of $column across matching rows, or null when no row matches (SQL MAX() of an empty set).

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |

**Returns:** `int\|float\|string\|null`

</details>

<details>
<summary><code>min()</code></summary>

#### `QueryBuilder min()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L453)

```php
public function min(string $column): int|float|string|null
```

Returns the minimum value of $column across matching rows, or null when no row matches (SQL MIN() of an empty set).

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |

**Returns:** `int\|float\|string\|null`

</details>

<details>
<summary><code>sum()</code></summary>

#### `QueryBuilder sum()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L466)

```php
public function sum(string $column): int|float
```

Returns the sum of $column across matching rows. SQL SUM() of an empty set is NULL; normalized to 0 since a caller asking for a sum virtually always wants a number, not a null check.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | Column name (trusted, not user input). |

**Returns:** `int\|float`

</details>

<details>
<summary><code>aggregate()</code></summary>

#### `QueryBuilder aggregate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L479)

```php
private function aggregate(string $fn, string $column): int|float|string|null
```

Runs a single-column SQL aggregate (MAX/MIN/SUM) over matching rows.

| Param | Type | Description |
|---|---|---|
| `$fn` | `string` | Aggregate function name — caller-controlled constant, not user input. |
| `$column` | `string` | Column name (trusted, not user input). |

**Returns:** `int\|float\|string\|null`

</details>

<details>
<summary><code>delete()</code></summary>

#### `QueryBuilder delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L502)

```php
public function delete(): int
```

Deletes every matching row in one statement. Soft-deletes (bulk UPDATE of deletedAtColumn) when the model uses soft deletes; otherwise a hard DELETE. Use forceDelete() to always hard-delete regardless.

**Returns:** `int` — Number of rows affected.

</details>

<details>
<summary><code>forceDelete()</code></summary>

#### `QueryBuilder forceDelete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L516)

```php
public function forceDelete(): int
```

Deletes every matching row outright in one statement, bypassing soft deletes.

**Returns:** `int` — Number of rows affected.

</details>

<details>
<summary><code>update()</code></summary>

#### `QueryBuilder update()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L536)

```php
public function update(array $attributes): int
```

Updates every matching row with the given column values in one statement. Does not auto-manage timestamps — include updatedAtColumn in $attributes if it should be refreshed. A Raw value is emitted verbatim instead of bound — e.g. ->update(['attempts' => new Raw('attempts + 1')]) for an atomic, concurrency-safe increment.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | — |

**Returns:** `int` — Number of rows affected.

</details>

<details>
<summary><code>buildSelect()</code></summary>

#### `QueryBuilder buildSelect()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L561)

```php
private function buildSelect(): array
```

Builds the full SELECT SQL string and parameter list.

**Returns:** `array{sql: string, params: list<mixed>}`

</details>

<details>
<summary><code>joinSql()</code></summary>

#### `QueryBuilder joinSql()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L587)

```php
private function joinSql(): string
```

Joins accumulated join()/leftJoin() clauses into one SQL fragment.

**Returns:** `string`

</details>

<details>
<summary><code>buildWhere()</code></summary>

#### `QueryBuilder buildWhere()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L599)

```php
private function buildWhere(): array
```

Builds the WHERE clause string and collects bound values, including the soft-delete filter implied by withTrashed()/onlyTrashed() when the model uses soft deletes.

**Returns:** `array{string, list<mixed>}`

</details>

<details>
<summary><code>renderCondition()</code></summary>

#### `QueryBuilder renderCondition()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L653)

```php
private function renderCondition(string $column, string $operator, mixed $value, array $params): string
```

Renders one WHERE condition, appending any bound parameter to $params by reference.

| Param | Type | Description |
|---|---|---|
| `$column` | `string` | — |
| `$operator` | `string` | — |
| `$value` | `mixed` | — |
| `$params` | `list<mixed>` | — |

**Returns:** `string`

</details>

<details>
<summary><code>softDeleteClause()</code></summary>

#### `QueryBuilder softDeleteClause()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/QueryBuilder.php#L674)

```php
private function softDeleteClause(): ?string
```

Soft-delete SQL fragment implied by the model and withTrashed()/onlyTrashed(), if any.

**Returns:** `string\|null`

</details>

## `Raw`

*final class* — `rafalmasiarek\DashboardKit\Model\Raw` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Raw.php#L13)

Wraps a raw SQL expression (e.g. 'NOW()') so QueryBuilder emits it unescaped in a WHERE clause instead of binding it as a parameter.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#raw-__construct) | public | — | — |

<details>
<summary><code>__construct()</code></summary>

#### `Raw __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Model/Raw.php#L18)

```php
public function __construct(string $sql)
```

| Param | Type | Description |
|---|---|---|
| `$sql` | `string` | Raw SQL fragment, emitted verbatim. |

</details>

## `ModuleRegistry`

*class* — `rafalmasiarek\DashboardKit\Module\ModuleRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L14)

Discovers and indexes application modules from a directory tree. Each module is a directory containing a module.php file that returns a configuration array. The registry tracks template paths and provides filtered views for navbar rendering and scheduling.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$modules` | `array<string, array<string, mixed>>` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`discover()`](#moduleregistry-discover) | public | `void` | Scans a directory for module.php files and registers each module found. Calling discover() multiple times merges results — later calls override earlier ones when slugs collide, allowing user modules to shadow built-ins. |
| [`all()`](#moduleregistry-all) | public | `array<string, array<string, mixed>>` | Returns all registered modules sorted by order. |
| [`find()`](#moduleregistry-find) | public | `array<string, mixed>\|null` | Finds a single module by its slug. |
| [`navbarItems()`](#moduleregistry-navbaritems) | public | `array<string, array<string, mixed>>` | Returns modules that should appear in the navigation bar. |
| [`resolvePath()`](#moduleregistry-resolvepath) | private static | `string\|null` | Resolves the effective navbar path for a module. Resolution order: 1. Explicit 'path' key. 2. For 'routes' modules: first GET route without dynamic segments ({param}). 3. Default: '/{slug}'. Returns null when a routes module has no static GET route — the navbar template should render the item as disabled in that case. |
| [`scheduledModules()`](#moduleregistry-scheduledmodules) | public | `array<string, array<string, mixed>>` | Returns modules that define at least one scheduled task. |

<details>
<summary><code>discover()</code></summary>

#### `ModuleRegistry discover()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L27)

```php
public function discover(string $dir): void
```

Scans a directory for module.php files and registers each module found. Calling discover() multiple times merges results — later calls override earlier ones when slugs collide, allowing user modules to shadow built-ins.

| Param | Type | Description |
|---|---|---|
| `$dir` | `string` | Root directory to scan (glob pattern: {dir}/[*]/module.php). |

</details>

<details>
<summary><code>all()</code></summary>

#### `ModuleRegistry all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L54)

```php
public function all(): array
```

Returns all registered modules sorted by order.

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>find()</code></summary>

#### `ModuleRegistry find()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L65)

```php
public function find(string $slug): ?array
```

Finds a single module by its slug.

| Param | Type | Description |
|---|---|---|
| `$slug` | `string` | Module slug identifier. |

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>navbarItems()</code></summary>

#### `ModuleRegistry navbarItems()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L75)

```php
public function navbarItems(): array
```

Returns modules that should appear in the navigation bar.

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>resolvePath()</code></summary>

#### `ModuleRegistry resolvePath()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L94)

```php
private static function resolvePath(array $module): ?string
```

Resolves the effective navbar path for a module. Resolution order: 1. Explicit 'path' key. 2. For 'routes' modules: first GET route without dynamic segments ({param}). 3. Default: '/{slug}'. Returns null when a routes module has no static GET route — the navbar template should render the item as disabled in that case.

| Param | Type | Description |
|---|---|---|
| `$module` | `array<string, mixed>` | — |

**Returns:** `string\|null`

</details>

<details>
<summary><code>scheduledModules()</code></summary>

#### `ModuleRegistry scheduledModules()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Module/ModuleRegistry.php#L123)

```php
public function scheduledModules(): array
```

Returns modules that define at least one scheduled task.

**Returns:** `array<string, array<string, mixed>>`

</details>

## `DashboardUserColumnsProvider`

*final class* — `rafalmasiarek\DashboardKit\Schema\DashboardUserColumnsProvider` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/DashboardUserColumnsProvider.php#L29)

Declares the extra columns on the users table that dashboard-kit always requires. Pass unconditionally to Auth::createSchema() so that role and active exist regardless of which login extensions are registered: $auth->createSchema(new DashboardUserColumnsProvider(), ...); Column semantics: role       — 'user' or 'admin'; governs access to the /admin panel. active     — 1 = account usable, 0 = pending activation or manually disabled. Defaults to 0 (safe minimum). Code that creates immediately-usable accounts must set active = 1 explicitly (admin panel, registration without require_activation). updated_at — Timestamp of the last mutation to any column in this row. MySQL: maintained automatically via ON UPDATE CURRENT_TIMESTAMP. SQLite: must be updated explicitly by application code on writes.

implements `SchemaProviderInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`additionalSchema()`](#dashboardusercolumnsprovider-additionalschema) | public | `list<string>` | — |

<details>
<summary><code>additionalSchema()</code></summary>

#### `DashboardUserColumnsProvider additionalSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/DashboardUserColumnsProvider.php#L35)

```php
public function additionalSchema(string $driver): array
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name. |

**Returns:** `list<string>`

</details>

## `ModuleSchemaBuilder`

*final class* — `rafalmasiarek\DashboardKit\Schema\ModuleSchemaBuilder` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L59)

Builds and executes CREATE TABLE IF NOT EXISTS statements from a declarative array. Supports MySQL and SQLite. Schema arrays are defined in module.php under the 'schema' key and processed by SchemaStateManager on every boot. System columns injected automatically (unless already defined by the user): id         — CHAR(36) NOT NULL, PRIMARY KEY (UUID v4, application-generated) created_at — DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP updated_at — DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, plus ON UPDATE CURRENT_TIMESTAMP on MySQL only (no SQLite equivalent), when 'timestamps' resolves to true (see constructor). deleted_at — DATETIME NULL DEFAULT NULL, matching Model's $softDeletes convention, when 'soft_deletes' resolves to true. A table's own 'timestamps'/'soft_deletes' key always wins; when a table doesn't set one, it falls back to this instance's $defaultTimestamps/ $defaultSoftDeletes constructor arguments — set by the app that constructs this builder (see Dashboard::initModuleSchemas() and its 'schema.timestamps'/'schema.soft_deletes' config keys), not hardcoded here. Table definition format: 'schema' => [ 'my_table' => [ 'timestamps'   => true,                    // override this builder's default 'soft_deletes' => true,                    // override this builder's default 'columns' => [ 'user_id' => ['type' => 'id_ref',      'null' => false], 'label'   => ['type' => 'varchar(255)', 'null' => false], 'note'    => ['type' => 'text',         'default' => null], ], 'indexes' => [ // Simple format (non-unique index): 'idx_user_id'  => ['user_id'], // Associative format (supports unique): 'uniq_label'   => ['columns' => ['label'], 'unique' => true], // Both formats may be mixed in one table. ], 'foreign_keys' => [ ['column' => 'user_id', 'references' => 'users', 'on' => 'id', 'on_delete' => 'CASCADE'], ], ], ] Special column type 'id_ref' always resolves to CHAR(36) regardless of driver, matching the UUID-based primary key used for all module tables.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#moduleschemabuilder-__construct) | public | — | — |
| [`build()`](#moduleschemabuilder-build) | public | `void` | Executes CREATE TABLE IF NOT EXISTS for every table in the schema definition. |
| [`statementsFor()`](#moduleschemabuilder-statementsfor) | public | `list<string>` | Generates all SQL statements needed to create one table. For MySQL, indexes and foreign keys are emitted inline inside CREATE TABLE. For SQLite, indexes are emitted as separate CREATE INDEX IF NOT EXISTS statements. |
| [`declaredColumns()`](#moduleschemabuilder-declaredcolumns) | public | `array<string, string>` | Returns all declared column DDL fragments keyed by column name, after auto-injection. Used by SchemaStateManager to detect missing columns and generate ALTER TABLE statements. |
| [`expandDefinition()`](#moduleschemabuilder-expanddefinition) | private | `array<string, mixed>` | Expands a raw table definition by injecting system columns. Injection rules: - 'id' prepended as CHAR(36) NOT NULL (UUID PK) unless the user defines their own 'id' column, their own 'primary', or sets 'no_id' => true (for a table whose PK is a different column, e.g. a token string, or a composite-key junction table with no single PK column at all). - 'created_at' appended as DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP if not defined. - 'updated_at' appended with ON UPDATE CURRENT_TIMESTAMP if 'timestamps' resolves to true (table's own key, falling back to $defaultTimestamps) and not already defined. - 'deleted_at' appended as DATETIME NULL DEFAULT NULL if 'soft_deletes' resolves to true (table's own key, falling back to $defaultSoftDeletes) and not already defined. User-defined columns always take priority over injected ones. |
| [`columnDdl()`](#moduleschemabuilder-columnddl) | private | `string` | Builds a single column definition fragment. |
| [`foreignKeyDdl()`](#moduleschemabuilder-foreignkeyddl) | private | `string` | Builds a FOREIGN KEY constraint fragment. |
| [`defaultFragment()`](#moduleschemabuilder-defaultfragment) | private | `string` | Generates the DEFAULT fragment for a column definition. Raw SQL expressions (CURRENT_TIMESTAMP, NULL) are emitted unquoted. |
| [`parseIndexDef()`](#moduleschemabuilder-parseindexdef) | private | `array{list<string>, bool}` | Parses an index definition into a column list and a unique flag. Supports two formats: - Simple (legacy): `['col1', 'col2']` — non-unique - Associative: `['columns' => ['col1'], 'unique' => true]` |
| [`mapType()`](#moduleschemabuilder-maptype) | private | `string` | Maps a generic type name to a driver-specific SQL type. 'id_ref' always resolves to CHAR(36) to match the UUID-based primary key convention. SQLite uses type affinity: integers → INTEGER, strings → TEXT, floats → REAL. |

<details>
<summary><code>__construct()</code></summary>

#### `ModuleSchemaBuilder __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L69)

```php
public function __construct(bool $defaultTimestamps, bool $defaultSoftDeletes)
```

| Param | Type | Description |
|---|---|---|
| `$defaultTimestamps` | `bool` | Fallback for a table that doesn't set its own 'timestamps' key. False keeps this builder's behavior unchanged for any caller that doesn't pass an argument. |
| `$defaultSoftDeletes` | `bool` | Fallback for a table that doesn't set its own 'soft_deletes' key. Same default rationale. |

</details>

<details>
<summary><code>build()</code></summary>

#### `ModuleSchemaBuilder build()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L80)

```php
public function build(array $tables, PDO $pdo): void
```

Executes CREATE TABLE IF NOT EXISTS for every table in the schema definition.

| Param | Type | Description |
|---|---|---|
| `$tables` | `array<string, array<string, mixed>>` | Map of table name → table definition. |
| `$pdo` | `PDO` | Active database connection. |

</details>

<details>
<summary><code>statementsFor()</code></summary>

#### `ModuleSchemaBuilder statementsFor()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L102)

```php
public function statementsFor(string $table, array $definition, string $driver): array
```

Generates all SQL statements needed to create one table. For MySQL, indexes and foreign keys are emitted inline inside CREATE TABLE. For SQLite, indexes are emitted as separate CREATE INDEX IF NOT EXISTS statements.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table name. |
| `$definition` | `array<string, mixed>` | Table definition array. |
| `$driver` | `string` | PDO driver name ('mysql' or 'sqlite'). |

**Returns:** `list<string>`

</details>

<details>
<summary><code>declaredColumns()</code></summary>

#### `ModuleSchemaBuilder declaredColumns()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L158)

```php
public function declaredColumns(array $definition, string $driver): array
```

Returns all declared column DDL fragments keyed by column name, after auto-injection. Used by SchemaStateManager to detect missing columns and generate ALTER TABLE statements.

| Param | Type | Description |
|---|---|---|
| `$definition` | `array<string, mixed>` | Table definition array. |
| `$driver` | `string` | PDO driver name. |

**Returns:** `array<string, string>` — Map of column name → full DDL fragment (e.g. "`col` VARCHAR(255) NOT NULL").

</details>

<details>
<summary><code>expandDefinition()</code></summary>

#### `ModuleSchemaBuilder expandDefinition()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L189)

```php
private function expandDefinition(array $definition): array
```

Expands a raw table definition by injecting system columns. Injection rules: - 'id'         prepended as CHAR(36) NOT NULL (UUID PK) unless the user defines their own 'id' column, their own 'primary', or sets 'no_id' => true (for a table whose PK is a different column, e.g. a token string, or a composite-key junction table with no single PK column at all). - 'created_at' appended as DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP if not defined. - 'updated_at' appended with ON UPDATE CURRENT_TIMESTAMP if 'timestamps' resolves to true (table's own key, falling back to $defaultTimestamps) and not already defined. - 'deleted_at' appended as DATETIME NULL DEFAULT NULL if 'soft_deletes' resolves to true (table's own key, falling back to $defaultSoftDeletes) and not already defined. User-defined columns always take priority over injected ones.

| Param | Type | Description |
|---|---|---|
| `$definition` | `array<string, mixed>` | Raw table definition. |

**Returns:** `array<string, mixed>` — Expanded definition with system columns merged in.

</details>

<details>
<summary><code>columnDdl()</code></summary>

#### `ModuleSchemaBuilder columnDdl()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L239)

```php
private function columnDdl(string $name, array $col, string $driver): string
```

Builds a single column definition fragment.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Column name. |
| `$col` | `array<string, mixed>` | Column options. |
| `$driver` | `string` | PDO driver name. |

**Returns:** `string`

</details>

<details>
<summary><code>foreignKeyDdl()</code></summary>

#### `ModuleSchemaBuilder foreignKeyDdl()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L259)

```php
private function foreignKeyDdl(array $fk): string
```

Builds a FOREIGN KEY constraint fragment.

| Param | Type | Description |
|---|---|---|
| `$fk` | `array<string, mixed>` | Foreign key definition. |

**Returns:** `string`

</details>

<details>
<summary><code>defaultFragment()</code></summary>

#### `ModuleSchemaBuilder defaultFragment()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L277)

```php
private function defaultFragment(array $col): string
```

Generates the DEFAULT fragment for a column definition. Raw SQL expressions (CURRENT_TIMESTAMP, NULL) are emitted unquoted.

| Param | Type | Description |
|---|---|---|
| `$col` | `array<string, mixed>` | — |

**Returns:** `string`

</details>

<details>
<summary><code>parseIndexDef()</code></summary>

#### `ModuleSchemaBuilder parseIndexDef()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L307)

```php
private function parseIndexDef(array $indexDef): array
```

Parses an index definition into a column list and a unique flag. Supports two formats: - Simple (legacy): `['col1', 'col2']`             — non-unique - Associative:     `['columns' => ['col1'], 'unique' => true]`

| Param | Type | Description |
|---|---|---|
| `$indexDef` | `array<mixed>` | Raw value from the 'indexes' map. |

**Returns:** `array{list<string>, bool}`

</details>

<details>
<summary><code>mapType()</code></summary>

#### `ModuleSchemaBuilder mapType()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/ModuleSchemaBuilder.php#L326)

```php
private function mapType(string $type, string $driver): string
```

Maps a generic type name to a driver-specific SQL type. 'id_ref' always resolves to CHAR(36) to match the UUID-based primary key convention. SQLite uses type affinity: integers → INTEGER, strings → TEXT, floats → REAL.

| Param | Type | Description |
|---|---|---|
| `$type` | `string` | Declared type from the schema array (e.g. 'int', 'varchar(255)', 'id_ref'). |
| `$driver` | `string` | PDO driver name. |

**Returns:** `string`

</details>

## `PasswordResetSchemaProvider`

*final class* — `rafalmasiarek\DashboardKit\Schema\PasswordResetSchemaProvider` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/PasswordResetSchemaProvider.php#L18)

Declares the password_resets table required by the password-reset flow. Pass to Auth::createSchema() only when password_reset is enabled: $auth->createSchema(new DashboardUserColumnsProvider(), new PasswordResetSchemaProvider(), ...);

implements `SchemaProviderInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`additionalSchema()`](#passwordresetschemaprovider-additionalschema) | public | `list<string>` | — |

<details>
<summary><code>additionalSchema()</code></summary>

#### `PasswordResetSchemaProvider additionalSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/PasswordResetSchemaProvider.php#L24)

```php
public function additionalSchema(string $driver): array
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name. |

**Returns:** `list<string>`

</details>

## `SchemaInspector`

*final class* — `rafalmasiarek\DashboardKit\Schema\SchemaInspector` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaInspector.php#L17)

Reads the actual column list of an existing database table. Used by SchemaStateManager to detect columns missing from the live schema so that ALTER TABLE ADD COLUMN statements can be generated for them.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`columnNames()`](#schemainspector-columnnames) | public | `list<string>` | Returns the column names that currently exist in the given table. Returns an empty array when the table does not exist yet. |
| [`mysqlColumnNames()`](#schemainspector-mysqlcolumnnames) | private | `list<string>` | Reads column names from INFORMATION_SCHEMA for MySQL/MariaDB. |
| [`sqliteColumnNames()`](#schemainspector-sqlitecolumnnames) | private | `list<string>` | Reads column names from PRAGMA table_info for SQLite. |

<details>
<summary><code>columnNames()</code></summary>

#### `SchemaInspector columnNames()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaInspector.php#L28)

```php
public function columnNames(string $table, PDO $pdo): array
```

Returns the column names that currently exist in the given table. Returns an empty array when the table does not exist yet.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table name. |
| `$pdo` | `PDO` | Active database connection. |

**Returns:** `list<string>`

</details>

<details>
<summary><code>mysqlColumnNames()</code></summary>

#### `SchemaInspector mysqlColumnNames()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaInspector.php#L44)

```php
private function mysqlColumnNames(string $table, PDO $pdo): array
```

Reads column names from INFORMATION_SCHEMA for MySQL/MariaDB.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | — |
| `$pdo` | `PDO` | — |

**Returns:** `list<string>`

</details>

<details>
<summary><code>sqliteColumnNames()</code></summary>

#### `SchemaInspector sqliteColumnNames()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaInspector.php#L64)

```php
private function sqliteColumnNames(string $table, PDO $pdo): array
```

Reads column names from PRAGMA table_info for SQLite.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | — |
| `$pdo` | `PDO` | — |

**Returns:** `list<string>`

</details>

## `SchemaStateManager`

*final class* — `rafalmasiarek\DashboardKit\Schema\SchemaStateManager` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L31)

Manages module schema lifecycle: creation, column diffing, and state tracking. On every boot, SchemaStateManager compares a hash of each table's declared schema (folded together with the injected ModuleSchemaBuilder's own default flags, so a config-level default change invalidates every table that relies on it) against the hash stored in `_schema_state`. Tables whose hash has not changed are skipped entirely — no INFORMATION_SCHEMA queries, no ALTER TABLE. Only tables with a changed or missing hash go through the full sync cycle: 1. CREATE TABLE IF NOT EXISTS  (idempotent, driver-specific DDL via ModuleSchemaBuilder) 2. Introspect actual columns   (SchemaInspector — only on hash mismatch) 3. ALTER TABLE ADD COLUMN      (only for columns declared but absent in DB) 4. Upsert _schema_state        (new hash + column count + timestamp) Columns are never dropped or modified — only added. New NOT NULL columns added to tables with existing rows must declare a DEFAULT value.

**Depends on:** `PDO`, [`ModuleSchemaBuilder`](#moduleschemabuilder), [`SchemaInspector`](#schemainspector), [`ClockInterface`](#clockinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#schemastatemanager-__construct) | public | — | — |
| [`sync()`](#schemastatemanager-sync) | public | `void` | Syncs all module schemas against the live database. |
| [`forAlter()`](#schemastatemanager-foralter) | private | `string` | Adapts a column DDL fragment for use in ALTER TABLE ADD COLUMN. SQLite rejects a non-constant default (CURRENT_TIMESTAMP included) on ADD COLUMN, even though the exact same default is accepted on CREATE TABLE — "Cannot add a column with non-constant default". Substituted with a literal snapshot of now, so the backfill succeeds; the column's default for any future INSERT on SQLite is this same frozen literal, a known, narrower version of the "no ON UPDATE equivalent on SQLite" limitation already documented on ModuleSchemaBuilder. MySQL accepts CURRENT_TIMESTAMP on ADD COLUMN natively and is left untouched. |
| [`collectTables()`](#schemastatemanager-collecttables) | private | `array<string, array{definition: array<string, mixed>, module: string}>` | Flattens all module schema definitions into a single table map. |
| [`loadStoredHashes()`](#schemastatemanager-loadstoredhashes) | private | `array<string, string>` | Loads stored hashes for all given table names in a single query. |
| [`ensureSystemTables()`](#schemastatemanager-ensuresystemtables) | private | `void` | Creates all system tables required by the dashboard infrastructure. Tables created (all prefixed with underscore to avoid collisions): _schema_state — hash + column count per module table; drives the schema sync fast path. _table_version — monotonic write counter per table; used by TableVersionTracker for version-keyed cache invalidation. _query_cache — serialized query results with TTL; used by QueryCacheStore. |
| [`upsertState()`](#schemastatemanager-upsertstate) | private | `void` | Inserts or updates the state record for one table. |

<details>
<summary><code>__construct()</code></summary>

#### `SchemaStateManager __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L43)

```php
public function __construct(PDO $pdo, ModuleSchemaBuilder $builder, SchemaInspector $inspector, string $tablePrefix, ?ClockInterface $clock)
```

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | Active database connection. |
| `$builder` | [`ModuleSchemaBuilder`](#moduleschemabuilder) | Builds DDL statements from declarative arrays. |
| `$inspector` | [`SchemaInspector`](#schemainspector) | Reads actual column lists from the live schema. |
| `$tablePrefix` | `string` | Optional prefix applied to every module table name. System tables (_schema_state, _table_version, _query_cache) are never prefixed. |
| `$clock` | `ClockInterface\|null` | Clock for the SQLite ALTER-backfill literal below. Defaults to SystemClock. |

</details>

<details>
<summary><code>sync()</code></summary>

#### `SchemaStateManager sync()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L58)

```php
public function sync(array $modules): void
```

Syncs all module schemas against the live database.

| Param | Type | Description |
|---|---|---|
| `$modules` | `array<string, array<string, mixed>>` | Map of module slug → module definition (only modules with an array 'schema' key are processed). |

</details>

<details>
<summary><code>forAlter()</code></summary>

#### `SchemaStateManager forAlter()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L116)

```php
private function forAlter(string $colDdl, string $driver): string
```

Adapts a column DDL fragment for use in ALTER TABLE ADD COLUMN. SQLite rejects a non-constant default (CURRENT_TIMESTAMP included) on ADD COLUMN, even though the exact same default is accepted on CREATE TABLE — "Cannot add a column with non-constant default". Substituted with a literal snapshot of now, so the backfill succeeds; the column's default for any future INSERT on SQLite is this same frozen literal, a known, narrower version of the "no ON UPDATE equivalent on SQLite" limitation already documented on ModuleSchemaBuilder. MySQL accepts CURRENT_TIMESTAMP on ADD COLUMN natively and is left untouched.

| Param | Type | Description |
|---|---|---|
| `$colDdl` | `string` | Column DDL fragment from declaredColumns(). |
| `$driver` | `string` | PDO driver name. |

**Returns:** `string`

</details>

<details>
<summary><code>collectTables()</code></summary>

#### `SchemaStateManager collectTables()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L133)

```php
private function collectTables(array $modules): array
```

Flattens all module schema definitions into a single table map.

| Param | Type | Description |
|---|---|---|
| `$modules` | `array<string, array<string, mixed>>` | — |

**Returns:** `array<string, array{definition: array<string, mixed>, module: string}>`

</details>

<details>
<summary><code>loadStoredHashes()</code></summary>

#### `SchemaStateManager loadStoredHashes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L155)

```php
private function loadStoredHashes(array $tableNames): array
```

Loads stored hashes for all given table names in a single query.

| Param | Type | Description |
|---|---|---|
| `$tableNames` | `list<string>` | — |

**Returns:** `array<string, string>` — Map of table name → stored hash.

</details>

<details>
<summary><code>ensureSystemTables()</code></summary>

#### `SchemaStateManager ensureSystemTables()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L177)

```php
private function ensureSystemTables(string $driver): void
```

Creates all system tables required by the dashboard infrastructure. Tables created (all prefixed with underscore to avoid collisions): _schema_state  — hash + column count per module table; drives the schema sync fast path. _table_version — monotonic write counter per table; used by TableVersionTracker for version-keyed cache invalidation. _query_cache   — serialized query results with TTL; used by QueryCacheStore.

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name ('mysql' or 'sqlite'). |

</details>

<details>
<summary><code>upsertState()</code></summary>

#### `SchemaStateManager upsertState()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SchemaStateManager.php#L254)

```php
private function upsertState(string $table, string $module, string $hash, int $count, string $driver): void
```

Inserts or updates the state record for one table.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Table name. |
| `$module` | `string` | Module slug that owns the table. |
| `$hash` | `string` | md5 hash of the serialized schema definition. |
| `$count` | `int` | Number of declared columns after auto-injection. |
| `$driver` | `string` | PDO driver name. |

</details>

## `SeedRunner`

*final class* — `rafalmasiarek\DashboardKit\Schema\SeedRunner` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SeedRunner.php#L73)

Runs declarative seed definitions against the live database. Seeds are idempotent: each row is skipped when a matching record already exists (matched by the 'match' key). Only missing rows are inserted. ## References between seeds Seeds run in definition order. A row may declare a `#ref` alias that makes its resolved `id` available to later rows via `@ref:alias.field` placeholders. 'users' => [ 'rows' => [ ['email' => 'admin@example.com', ..., '#ref' => 'admin'], ], ], 'tibia_user_prefs' => [ 'rows' => [ ['user_id' => '@ref:admin.id', 'notify_daily' => 1], ], ], `#ref` is stripped before insertion. `@ref:alias.field` is resolved against the stored column map of the referenced row (including the generated `id`). If the ref alias is unknown the placeholder resolves to `null`. ## Format <code> 'seed' => [ 'users' => [ 'match'   => 'email',        // unique column to check before inserting 'with_id' => true,           // auto-generate UUID for id if absent (default: true) 'rows'    => [ [ 'email'         => 'admin@example.com', 'role'          => 'admin', 'active'        => 1, 'password_hash' => password_hash('secret', PASSWORD_BCRYPT), '#ref'          => 'admin', ], ], ], 'api_scopes' => [ 'match'   => 'name', 'with_id' => false,          // table uses AUTO_INCREMENT — skip UUID injection 'rows'    => [ ['name' => 'shorts:read',  'description' => 'Read short links'], ['name' => 'shorts:write', 'description' => 'Manage short links'], ], ], ], </code> 'with_id' defaults to true. Set it to false for tables whose id column is AUTO_INCREMENT (i.e. tables that declare id explicitly in their schema columns instead of relying on the framework's UUID auto-injection). 'created_at' is never auto-injected: tables created by ModuleSchemaBuilder always have DEFAULT CURRENT_TIMESTAMP on that column, so it is optional in seed rows and the database fills it in automatically.

**Depends on:** `PDO`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#seedrunner-__construct) | public | — | — |
| [`run()`](#seedrunner-run) | public | `void` | Execute all seed definitions in order. Seeds run in PHP array order. Rows tagged with '#ref' register their resolved id so that subsequent rows can use '@ref:alias.field' placeholders. |
| [`insertIfMissing()`](#seedrunner-insertifmissing) | private | `string\|null` | Insert a row only when no matching record exists. When $withId is true the existence check fetches the existing id so it can be registered as a ref. When $withId is false (AUTO_INCREMENT / no UUID id column) COUNT(*) is used and the method returns null — those tables have nothing to resolve via '@ref:'. When $matchKey is empty the check is skipped and the row is always inserted. |
| [`generateUuid()`](#seedrunner-generateuuid) | private | `string` | Generate a UUID v4 string. |

<details>
<summary><code>__construct()</code></summary>

#### `SeedRunner __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SeedRunner.php#L79)

```php
public function __construct(PDO $pdo, string $tablePrefix)
```

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | Raw database connection (bypasses CachingPdo). |
| `$tablePrefix` | `string` | Optional prefix applied to every table name. Default: ''. |

</details>

<details>
<summary><code>run()</code></summary>

#### `SeedRunner run()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SeedRunner.php#L96)

```php
public function run(array $seeds): void
```

Execute all seed definitions in order. Seeds run in PHP array order. Rows tagged with '#ref' register their resolved id so that subsequent rows can use '@ref:alias.field' placeholders.

| Param | Type | Description |
|---|---|---|
| `$seeds` | `array<string, array{match?: string, with_id?: bool, rows: list<array<string, mixed>>}>` | Map of table name → seed definition. |

</details>

<details>
<summary><code>insertIfMissing()</code></summary>

#### `SeedRunner insertIfMissing()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SeedRunner.php#L173)

```php
private function insertIfMissing(string $table, array $row, string $matchKey, bool $withId, bool $ignoreDuplicates): string|null
```

Insert a row only when no matching record exists. When $withId is true the existence check fetches the existing id so it can be registered as a ref. When $withId is false (AUTO_INCREMENT / no UUID id column) COUNT(*) is used and the method returns null — those tables have nothing to resolve via '@ref:'. When $matchKey is empty the check is skipped and the row is always inserted.

| Param | Type | Description |
|---|---|---|
| `$table` | `string` | Target table name. |
| `$row` | `array<string, mixed>` | Column → value map. |
| `$matchKey` | `string` | Column name used for the existence check. |
| `$withId` | `bool` | Whether the table has a UUID id column. |
| `$ignoreDuplicates` | `bool` | — |

**Returns:** `string\|null` — Resolved id of the row, or null when unavailable.

</details>

<details>
<summary><code>generateUuid()</code></summary>

#### `SeedRunner generateUuid()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/SeedRunner.php#L216)

```php
private function generateUuid(): string
```

Generate a UUID v4 string.

**Returns:** `string` — Formatted as xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx.

| Throws | When |
|---|---|
| `\Random\RandomException` | On PRNG failure (PHP 8.2+). |

</details>

## `UserFieldsSchemaProvider`

*final class* — `rafalmasiarek\DashboardKit\Schema\UserFieldsSchemaProvider` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/UserFieldsSchemaProvider.php#L19)

Declares ALTER TABLE statements for project-configured custom user fields. Pass to Auth::createSchema() so that extra profile columns are created alongside the core schema: $auth->createSchema(..., new UserFieldsSchemaProvider($userFields));

implements `SchemaProviderInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#userfieldsschemaprovider-__construct) | public | — | — |
| [`additionalSchema()`](#userfieldsschemaprovider-additionalschema) | public | `list<string>` | — |
| [`sqlType()`](#userfieldsschemaprovider-sqltype) | private | `string` | Maps a user-field definition to a SQL column type for the given driver. |

<details>
<summary><code>__construct()</code></summary>

#### `UserFieldsSchemaProvider __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/UserFieldsSchemaProvider.php#L24)

```php
public function __construct(array $fields)
```

| Param | Type | Description |
|---|---|---|
| `$fields` | `array<string, array{label: string, type: string, max: int, required: bool}>` | — |

</details>

<details>
<summary><code>additionalSchema()</code></summary>

#### `UserFieldsSchemaProvider additionalSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/UserFieldsSchemaProvider.php#L32)

```php
public function additionalSchema(string $driver): array
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name. |

**Returns:** `list<string>`

</details>

<details>
<summary><code>sqlType()</code></summary>

#### `UserFieldsSchemaProvider sqlType()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Schema/UserFieldsSchemaProvider.php#L52)

```php
private function sqlType(array $field, string $driver): string
```

Maps a user-field definition to a SQL column type for the given driver.

| Param | Type | Description |
|---|---|---|
| `$field` | `array{label: string, type: string, max: int, required: bool}` | — |
| `$driver` | `string` | — |

**Returns:** `string`

</details>

## `SecretRegistry`

*final class* — `rafalmasiarek\DashboardKit\Security\SecretRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L12)

Central registry of sensitive values to be redacted from messages and stack traces.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$values` | `array<int, string>` | private static | Registered secret values. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#secretregistry-__construct) | private | — | — |
| [`addValue()`](#secretregistry-addvalue) | public static | `void` | Register a single secret value. No-op when the value is null, empty, or shorter than MIN_LEN. |
| [`addValues()`](#secretregistry-addvalues) | public static | `void` | Register multiple secret values at once. |
| [`primeFromConfig()`](#secretregistry-primefromconfig) | public static | `void` | Recursively scan a config array and register values whose key path looks sensitive. Matched key path segments (case-insensitive): secret, password, passwd, pwd, token, api_key, api-key, smtp.*password, _key, _pass, _hash, _salt, _pem, _kek, credential |
| [`redactValues()`](#secretregistry-redactvalues) | public static | `string` | Replace all registered secret values in a string with "****". Returns the original string unchanged when no secrets are registered. |
| [`primeFromEnv()`](#secretregistry-primefromenv) | public static | `void` | Scan $_ENV and $_SERVER for keys that look sensitive and register their values. Uses the same key pattern as the Whoops error-page blacklist in Dashboard. |
| [`reset()`](#secretregistry-reset) | public static | `void` | Clear all registered values (primarily useful in tests). |

<details>
<summary><code>__construct()</code></summary>

#### `SecretRegistry __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L28)

```php
private function __construct()
```

</details>

<details>
<summary><code>addValue()</code></summary>

#### `SecretRegistry addValue()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L38)

```php
public static function addValue(?string $value): void
```

Register a single secret value. No-op when the value is null, empty, or shorter than MIN_LEN.

| Param | Type | Description |
|---|---|---|
| `$value` | `string\|null` | — |

</details>

<details>
<summary><code>addValues()</code></summary>

#### `SecretRegistry addValues()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L58)

```php
public static function addValues(iterable $values): void
```

Register multiple secret values at once.

| Param | Type | Description |
|---|---|---|
| `$values` | `iterable<string>` | — |

</details>

<details>
<summary><code>primeFromConfig()</code></summary>

#### `SecretRegistry primeFromConfig()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L75)

```php
public static function primeFromConfig(array $config): void
```

Recursively scan a config array and register values whose key path looks sensitive. Matched key path segments (case-insensitive): secret, password, passwd, pwd, token, api_key, api-key, smtp.*password, _key, _pass, _hash, _salt, _pem, _kek, credential

| Param | Type | Description |
|---|---|---|
| `$config` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>redactValues()</code></summary>

#### `SecretRegistry redactValues()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L107)

```php
public static function redactValues(string $message): string
```

Replace all registered secret values in a string with "****". Returns the original string unchanged when no secrets are registered.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>primeFromEnv()</code></summary>

#### `SecretRegistry primeFromEnv()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L129)

```php
public static function primeFromEnv(): void
```

Scan $_ENV and $_SERVER for keys that look sensitive and register their values. Uses the same key pattern as the Whoops error-page blacklist in Dashboard.

</details>

<details>
<summary><code>reset()</code></summary>

#### `SecretRegistry reset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Security/SecretRegistry.php#L144)

```php
public static function reset(): void
```

Clear all registered values (primarily useful in tests).

</details>

## `LazyPhpSessionTransport`

*final class* — `rafalmasiarek\DashboardKit\Transport\LazyPhpSessionTransport` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L24)

Session transport that defers session_start() until the session is first accessed. The default PhpSessionTransport calls session_start() inside initialize(), which is invoked by Auth::__construct(). When Auth is resolved eagerly during container bootstrap — before any request middleware runs — any PHP notice or deprecation written to output by the DI container causes "headers already sent", making session_start() fail. This transport skips initialize() as a no-op. The session is started lazily on the first actual read or write, by which point the SessionMiddleware has already run and the session is active — making this a safe no-op itself.

implements `TokenTransportInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$key` | `string` | private | key used to store the auth token. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#lazyphpsessiontransport-__construct) | public | — | — |
| [`initialize()`](#lazyphpsessiontransport-initialize) | public | `void` | No-op — session is started lazily on first access. |
| [`get()`](#lazyphpsessiontransport-get) | public | `string\|null` | Returns the stored token, starting the session if not yet active. |
| [`set()`](#lazyphpsessiontransport-set) | public | `void` | Stores the token in the session, starting it if not yet active. |
| [`clear()`](#lazyphpsessiontransport-clear) | public | `void` | Removes the token from the session, starting it if not yet active. |
| [`ensureSession()`](#lazyphpsessiontransport-ensuresession) | private | `void` | Starts the PHP session if not yet active. |

<details>
<summary><code>__construct()</code></summary>

#### `LazyPhpSessionTransport __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L32)

```php
public function __construct(string $key)
```

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | $_SESSION key (default: 'auth_token'). |

</details>

<details>
<summary><code>initialize()</code></summary>

#### `LazyPhpSessionTransport initialize()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L42)

```php
public function initialize(): void
```

No-op — session is started lazily on first access.

</details>

<details>
<summary><code>get()</code></summary>

#### `LazyPhpSessionTransport get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L49)

```php
public function get(): ?string
```

Returns the stored token, starting the session if not yet active.

**Returns:** `string\|null`

</details>

<details>
<summary><code>set()</code></summary>

#### `LazyPhpSessionTransport set()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L64)

```php
public function set(string $token): void
```

Stores the token in the session, starting it if not yet active.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

</details>

<details>
<summary><code>clear()</code></summary>

#### `LazyPhpSessionTransport clear()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L76)

```php
public function clear(): void
```

Removes the token from the session, starting it if not yet active.

</details>

<details>
<summary><code>ensureSession()</code></summary>

#### `LazyPhpSessionTransport ensureSession()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Transport/LazyPhpSessionTransport.php#L88)

```php
private function ensureSession(): void
```

Starts the PHP session if not yet active.

</details>

## `CsrfExtension`

*class* — `rafalmasiarek\DashboardKit\Twig\CsrfExtension` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/CsrfExtension.php#L27)

Twig extension that exposes the csrf_field() function for use in templates. Usage in any form: {{ csrf_field('login') }} Renders via the package's own HtmlHelper::input(), which emits: <input type="hidden" name="_csrf" value="GENERATED_TOKEN"> <input type="hidden" name="_csrf_container" value="login"> <input type="hidden" name="_csrf_proof" value="PROOF">   (only when a session-bound proof is available) The container name isolates tokens per form — a token generated for 'login' cannot be replayed on 'register' or any module form.

extends `AbstractExtension`

**Depends on:** `Csrf`, `RealIpResolver`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#csrfextension-__construct) | public | — | — |
| [`getFunctions()`](#csrfextension-getfunctions) | public | `list<TwigFunction>` | — |
| [`renderField()`](#csrfextension-renderfield) | public | `string` | Renders the hidden CSRF inputs for a named container. $ip defaults to the resolved real client IP (see RealIpResolver above); $userAgent defaults to Csrf's own auto-detection. Both accept an explicit override for callers with a reason to bind the token to something other than the current request (e.g. issuing a token server-side, outside of a normal request/response cycle). |

<details>
<summary><code>__construct()</code></summary>

#### `CsrfExtension __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/CsrfExtension.php#L35)

```php
public function __construct(Csrf $csrf, RealIpResolver $ipResolver)
```

| Param | Type | Description |
|---|---|---|
| `$csrf` | `Csrf` | CSRF token service. |
| `$ipResolver` | `RealIpResolver` | Resolves the real client IP behind trusted proxies (e.g. Cloudflare), so tokens are issued bound to the same address CsrfMiddleware validates against. |

</details>

<details>
<summary><code>getFunctions()</code></summary>

#### `CsrfExtension getFunctions()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/CsrfExtension.php#L44)

```php
public function getFunctions(): array
```

**Returns:** `list<TwigFunction>`

</details>

<details>
<summary><code>renderField()</code></summary>

#### `CsrfExtension renderField()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/CsrfExtension.php#L64)

```php
public function renderField(string $container, ?string $ip, ?string $userAgent): string
```

Renders the hidden CSRF inputs for a named container. $ip defaults to the resolved real client IP (see RealIpResolver above); $userAgent defaults to Csrf's own auto-detection. Both accept an explicit override for callers with a reason to bind the token to something other than the current request (e.g. issuing a token server-side, outside of a normal request/response cycle).

| Param | Type | Description |
|---|---|---|
| `$container` | `string` | Unique form identifier (e.g. 'login', 'register', module slug). |
| `$ip` | `string\|null` | Optional client IP override. |
| `$userAgent` | `string\|null` | Optional User-Agent override. |

**Returns:** `string` — HTML-safe hidden input markup.

</details>

## `DashboardExtension`

*class* — `rafalmasiarek\DashboardKit\Twig\DashboardExtension` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/DashboardExtension.php#L13)

General-purpose Twig functions for the dashboard layout.

extends `AbstractExtension`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`getFunctions()`](#dashboardextension-getfunctions) | public | `list<TwigFunction>` | — |
| [`gravatarUrl()`](#dashboardextension-gravatarurl) | public | `string` | Returns the Gravatar URL for the given email address. Falls back to identicon when no Gravatar is registered. |

<details>
<summary><code>getFunctions()</code></summary>

#### `DashboardExtension getFunctions()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/DashboardExtension.php#L18)

```php
public function getFunctions(): array
```

**Returns:** `list<TwigFunction>`

</details>

<details>
<summary><code>gravatarUrl()</code></summary>

#### `DashboardExtension gravatarUrl()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Twig/DashboardExtension.php#L34)

```php
public function gravatarUrl(string $email, int $size): string
```

Returns the Gravatar URL for the given email address. Falls back to identicon when no Gravatar is registered.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | User email address. |
| `$size` | `int` | Avatar size in pixels (1–2048). Default: 40. |

**Returns:** `string` — HTTPS URL to the avatar image.

</details>

## `ClockInterface`

*interface* — `rafalmasiarek\DashboardKit\Utils\ClockInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L17)

Timezone-aware clock contract, extending PSR-20 with formatting/timezone helpers.

extends `PsrClockInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`tzId()`](#clockinterface-tzid) | public | `string` | Resolves the effective timezone identifier. |
| [`tz()`](#clockinterface-tz) | public | `DateTimeZone` | Returns the effective DateTimeZone instance. |
| [`nowUtc()`](#clockinterface-nowutc) | public | `DateTimeImmutable` | Returns the current time in UTC. |
| [`at()`](#clockinterface-at) | public | `DateTimeImmutable` | Creates a DateTimeImmutable for the given time string in this clock's timezone. |
| [`fromInterface()`](#clockinterface-frominterface) | public | `DateTimeImmutable` | Converts an arbitrary DateTimeInterface into an immutable instance in this clock's timezone. |
| [`formatAtomFromTimestamp()`](#clockinterface-formatatomfromtimestamp) | public | `string` | Formats a unix timestamp as DATE_ATOM in this clock's timezone. |
| [`formatAtom()`](#clockinterface-formatatom) | public | `string` | Formats a DateTimeInterface as DATE_ATOM in this clock's timezone. |
| [`formatSqlUtc()`](#clockinterface-formatsqlutc) | public | `string` | Formats a DateTimeInterface as SQL DATETIME in UTC ("Y-m-d H:i:s"). |
| [`formatMinuteKey()`](#clockinterface-formatminutekey) | public | `string` | Returns a minute slot key (YmdHi) for a DateTimeInterface in this clock's timezone. |
| [`formatMinuteKeyFromTimestamp()`](#clockinterface-formatminutekeyfromtimestamp) | public | `string` | Returns a minute slot key (YmdHi) for a unix timestamp in this clock's timezone. |

<details>
<summary><code>tzId()</code></summary>

#### `ClockInterface tzId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L24)

```php
public function tzId(): string
```

Resolves the effective timezone identifier.

**Returns:** `string`

</details>

<details>
<summary><code>tz()</code></summary>

#### `ClockInterface tz()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L31)

```php
public function tz(): DateTimeZone
```

Returns the effective DateTimeZone instance.

**Returns:** `DateTimeZone`

</details>

<details>
<summary><code>nowUtc()</code></summary>

#### `ClockInterface nowUtc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L38)

```php
public function nowUtc(): DateTimeImmutable
```

Returns the current time in UTC.

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>at()</code></summary>

#### `ClockInterface at()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L46)

```php
public function at(string $timeStr): DateTimeImmutable
```

Creates a DateTimeImmutable for the given time string in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$timeStr` | `string` | — |

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>fromInterface()</code></summary>

#### `ClockInterface fromInterface()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L54)

```php
public function fromInterface(DateTimeInterface $dt): DateTimeImmutable
```

Converts an arbitrary DateTimeInterface into an immutable instance in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>formatAtomFromTimestamp()</code></summary>

#### `ClockInterface formatAtomFromTimestamp()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L62)

```php
public function formatAtomFromTimestamp(int $ts): string
```

Formats a unix timestamp as DATE_ATOM in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$ts` | `int` | Unix timestamp. |

**Returns:** `string`

</details>

<details>
<summary><code>formatAtom()</code></summary>

#### `ClockInterface formatAtom()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L70)

```php
public function formatAtom(DateTimeInterface $dt): string
```

Formats a DateTimeInterface as DATE_ATOM in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatSqlUtc()</code></summary>

#### `ClockInterface formatSqlUtc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L78)

```php
public function formatSqlUtc(DateTimeInterface $dt): string
```

Formats a DateTimeInterface as SQL DATETIME in UTC ("Y-m-d H:i:s").

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatMinuteKey()</code></summary>

#### `ClockInterface formatMinuteKey()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L86)

```php
public function formatMinuteKey(DateTimeInterface $dt): string
```

Returns a minute slot key (YmdHi) for a DateTimeInterface in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatMinuteKeyFromTimestamp()</code></summary>

#### `ClockInterface formatMinuteKeyFromTimestamp()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/ClockInterface.php#L94)

```php
public function formatMinuteKeyFromTimestamp(int $ts): string
```

Returns a minute slot key (YmdHi) for a unix timestamp in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$ts` | `int` | Unix timestamp. |

**Returns:** `string`

</details>

## `PasswordStrength`

*class* — `rafalmasiarek\DashboardKit\Utils\PasswordStrength` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/PasswordStrength.php#L22)

Configurable password strength scorer returning a score in the range 0–4. Rules are named arrays merged recursively with DEFAULT_RULES. Pass only the keys you want to override — everything else inherits the default. Scoring pipeline: 1. Forbidden characters → instant score 0 (no further evaluation). 2. Positive bonuses: min_length, diversity, digit_ratio, special_ratio. 3. Soft penalties: no_repeats, no_sequences (subtracted from bonus total). 4. final = max(0, earned − penalties) / max_possible × 5, clamped to 0–4. The companion JavaScript in register.twig mirrors this logic exactly using the resolved rule set serialised as JSON, so client-side and server-side always agree.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`score()`](#passwordstrength-score) | public static | `int` | Returns the strength score for the given password. |
| [`hasSequence()`](#passwordstrength-hassequence) | private static | `bool` | Returns whether the password contains an ascending or descending character sequence of at least the given length (case-insensitive). Examples (length=3): "abc", "XYZ", "321", "cba" all match. |

<details>
<summary><code>score()</code></summary>

#### `PasswordStrength score()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/PasswordStrength.php#L85)

```php
public static function score(string $password, array $ruleOverrides): int
```

Returns the strength score for the given password.

| Param | Type | Description |
|---|---|---|
| `$password` | `string` | Plain-text password. |
| `$ruleOverrides` | `array<string, array<string, mixed>>` | Partial overrides merged into DEFAULT_RULES. |

**Returns:** `int` — Score 0–4.

</details>

<details>
<summary><code>hasSequence()</code></summary>

#### `PasswordStrength hasSequence()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/PasswordStrength.php#L179)

```php
private static function hasSequence(string $password, int $length): bool
```

Returns whether the password contains an ascending or descending character sequence of at least the given length (case-insensitive). Examples (length=3): "abc", "XYZ", "321", "cba" all match.

| Param | Type | Description |
|---|---|---|
| `$password` | `string` | — |
| `$length` | `int` | Minimum run length to flag. |

**Returns:** `bool`

</details>

## `SystemClock`

*final class* — `rafalmasiarek\DashboardKit\Utils\SystemClock` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/SystemClock.php#L20)

Default ClockInterface — plain system time, no timezone awareness. Used when nothing else is bound to ClockInterface::class. An app with its own timezone-aware clock should override this binding after Dashboard::create() — the same pattern already used for MailerInterface, RealIpResolver, and GeoIpDriverInterface.

implements [`ClockInterface`](#clockinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`now()`](#systemclock-now) | public | `DateTimeImmutable` | {@inheritdoc} |

<details>
<summary><code>now()</code></summary>

#### `SystemClock now()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/SystemClock.php#L25)

```php
public function now(): DateTimeImmutable
```

{@inheritdoc}

**Returns:** `DateTimeImmutable`

</details>

## `TimezoneClock`

*final class* — `rafalmasiarek\DashboardKit\Utils\TimezoneClock` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L17)

Default implementation of the richer ClockInterface — defers entirely to PHP's own default timezone, no configuration of its own.

implements [`ClockInterface`](#clockinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`tzId()`](#timezoneclock-tzid) | public | `string` | Resolves the effective timezone identifier. |
| [`tz()`](#timezoneclock-tz) | public | `DateTimeZone` | Returns the effective DateTimeZone instance. |
| [`now()`](#timezoneclock-now) | public | `DateTimeImmutable` | {@inheritdoc} |
| [`nowUtc()`](#timezoneclock-nowutc) | public | `DateTimeImmutable` | Returns the current time in UTC. |
| [`at()`](#timezoneclock-at) | public | `DateTimeImmutable` | Creates a DateTimeImmutable for the given time string in this clock's timezone. |
| [`fromInterface()`](#timezoneclock-frominterface) | public | `DateTimeImmutable` | Converts an arbitrary DateTimeInterface into an immutable instance in this clock's timezone. |
| [`formatAtomFromTimestamp()`](#timezoneclock-formatatomfromtimestamp) | public | `string` | Formats a unix timestamp as DATE_ATOM in this clock's timezone. |
| [`formatAtom()`](#timezoneclock-formatatom) | public | `string` | Formats a DateTimeInterface as DATE_ATOM in this clock's timezone. |
| [`formatSqlUtc()`](#timezoneclock-formatsqlutc) | public | `string` | Formats a DateTimeInterface as SQL DATETIME in UTC ("Y-m-d H:i:s"). |
| [`formatMinuteKey()`](#timezoneclock-formatminutekey) | public | `string` | Returns a minute slot key (YmdHi) for a DateTimeInterface in this clock's timezone. |
| [`formatMinuteKeyFromTimestamp()`](#timezoneclock-formatminutekeyfromtimestamp) | public | `string` | Returns a minute slot key (YmdHi) for a unix timestamp in this clock's timezone. |

<details>
<summary><code>tzId()</code></summary>

#### `TimezoneClock tzId()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L22)

```php
public function tzId(): string
```

Resolves the effective timezone identifier.

**Returns:** `string`

</details>

<details>
<summary><code>tz()</code></summary>

#### `TimezoneClock tz()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L30)

```php
public function tz(): DateTimeZone
```

Returns the effective DateTimeZone instance.

**Returns:** `DateTimeZone`

</details>

<details>
<summary><code>now()</code></summary>

#### `TimezoneClock now()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L38)

```php
public function now(): DateTimeImmutable
```

{@inheritdoc}

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>nowUtc()</code></summary>

#### `TimezoneClock nowUtc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L46)

```php
public function nowUtc(): DateTimeImmutable
```

Returns the current time in UTC.

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>at()</code></summary>

#### `TimezoneClock at()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L54)

```php
public function at(string $timeStr): DateTimeImmutable
```

Creates a DateTimeImmutable for the given time string in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$timeStr` | `string` | — |

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>fromInterface()</code></summary>

#### `TimezoneClock fromInterface()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L62)

```php
public function fromInterface(DateTimeInterface $dt): DateTimeImmutable
```

Converts an arbitrary DateTimeInterface into an immutable instance in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `DateTimeImmutable`

</details>

<details>
<summary><code>formatAtomFromTimestamp()</code></summary>

#### `TimezoneClock formatAtomFromTimestamp()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L70)

```php
public function formatAtomFromTimestamp(int $ts): string
```

Formats a unix timestamp as DATE_ATOM in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$ts` | `int` | Unix timestamp. |

**Returns:** `string`

</details>

<details>
<summary><code>formatAtom()</code></summary>

#### `TimezoneClock formatAtom()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L78)

```php
public function formatAtom(DateTimeInterface $dt): string
```

Formats a DateTimeInterface as DATE_ATOM in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatSqlUtc()</code></summary>

#### `TimezoneClock formatSqlUtc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L86)

```php
public function formatSqlUtc(DateTimeInterface $dt): string
```

Formats a DateTimeInterface as SQL DATETIME in UTC ("Y-m-d H:i:s").

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatMinuteKey()</code></summary>

#### `TimezoneClock formatMinuteKey()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L94)

```php
public function formatMinuteKey(DateTimeInterface $dt): string
```

Returns a minute slot key (YmdHi) for a DateTimeInterface in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$dt` | `DateTimeInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>formatMinuteKeyFromTimestamp()</code></summary>

#### `TimezoneClock formatMinuteKeyFromTimestamp()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/TimezoneClock.php#L102)

```php
public function formatMinuteKeyFromTimestamp(int $ts): string
```

Returns a minute slot key (YmdHi) for a unix timestamp in this clock's timezone.

| Param | Type | Description |
|---|---|---|
| `$ts` | `int` | Unix timestamp. |

**Returns:** `string`

</details>

## `Uuid`

*class* — `rafalmasiarek\DashboardKit\Utils\Uuid` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/Uuid.php#L10)

Generates and validates UUID v4 identifiers.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`v4()`](#uuid-v4) | public static | `string` | Generates a cryptographically random UUID v4. |
| [`isValid()`](#uuid-isvalid) | public static | `bool` | Returns whether the given string is a valid UUID v4. |

<details>
<summary><code>v4()</code></summary>

#### `Uuid v4()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/Uuid.php#L17)

```php
public static function v4(): string
```

Generates a cryptographically random UUID v4.

**Returns:** `string` — UUID in xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx format.

</details>

<details>
<summary><code>isValid()</code></summary>

#### `Uuid isValid()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/Uuid.php#L32)

```php
public static function isValid(string $uuid): bool
```

Returns whether the given string is a valid UUID v4.

| Param | Type | Description |
|---|---|---|
| `$uuid` | `string` | — |

**Returns:** `bool`

</details>

## `UuidUserIdPolicy`

*final class* — `rafalmasiarek\DashboardKit\Utils\UuidUserIdPolicy` · [source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/UuidUserIdPolicy.php#L22)

Assigns a UUID v4 string as the user ID on creation. Use this policy when the users table should use a CHAR(36) primary key instead of an integer auto-increment column. Pass to PdoUserStorage: new PdoUserStorage($pdo, new UuidUserIdPolicy()) The schema is created automatically by Auth::createSchema() or PdoUserStorage::createSchema() using the column definitions below.

implements `UserIdPolicyInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`generate()`](#uuiduseridpolicy-generate) | public | `string` | Generate a UUID v4 string to use as the new user's primary key. |
| [`idColumnDefinition()`](#uuiduseridpolicy-idcolumndefinition) | public | `string` | — |
| [`userIdForeignType()`](#uuiduseridpolicy-useridforeigntype) | public | `string` | — |

<details>
<summary><code>generate()</code></summary>

#### `UuidUserIdPolicy generate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/UuidUserIdPolicy.php#L29)

```php
public function generate(): string
```

Generate a UUID v4 string to use as the new user's primary key.

**Returns:** `string` — UUID v4 in xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx format.

</details>

<details>
<summary><code>idColumnDefinition()</code></summary>

#### `UuidUserIdPolicy idColumnDefinition()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/UuidUserIdPolicy.php#L37)

```php
public function idColumnDefinition(string $driver): string
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>userIdForeignType()</code></summary>

#### `UuidUserIdPolicy userIdForeignType()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit/blob/main/src/Utils/UuidUserIdPolicy.php#L45)

```php
public function userIdForeignType(string $driver): string
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | — |

**Returns:** `string`

</details>




<!-- references -->

[repo_link]: https://github.com/rafalmasiarek/php-dashboard-kit
