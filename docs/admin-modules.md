# Admin Modules

Admin modules extend the built-in `/admin` panel with custom sub-pages. They follow the same structure as regular modules but are automatically protected by the `admin` role — no auth configuration needed.

## Directory structure

```
your-app/
└── admin_modules/
    └── stats/
        ├── module.php          # required — module definition
        └── templates/          # optional — Twig templates
            └── index.twig
```

The `admin_modules/` directory defaults to `{rootDir}/admin_modules`. Override it in config:

```php
Dashboard::create(__DIR__ . '/../', [
    'admin_modules_dir' => __DIR__ . '/../admin_modules',
]);
```

## module.php structure

`module.php` must return an array. The only required key is `slug`.

```php
<?php
return [
    // --- Identity ---
    'slug'        => 'stats',       // URL segment: /admin/stats
    'title'       => 'Stats',       // card title on /admin landing page
    'icon'        => '📊',          // emoji shown on the card
    'description' => 'Application statistics.',  // card subtitle on /admin landing page
    'order'       => 10,            // card sort order (lower = first)

    // --- Handler (choose one style) ---
    'render'  => fn(...) => ...,    // simple page — handles GET /admin/stats
    'handle'  => fn(...) => ...,    // optional POST handler for the same path
    // OR
    'routes'  => [...],             // multi-route (CRUD etc.) — same format as regular modules
];
```

`auth_required` is not a supported key — admin modules are always restricted to the `admin` role.

---

## Simple page module

A module with only a `render` key handles `GET /admin/{slug}`.

```php
<?php
return [
    'slug'        => 'stats',
    'title'       => 'Stats',
    'icon'        => '📊',
    'description' => 'Application statistics.',

    'render' => function ($req, $res, $view, $db, $c) {
        $userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return $view->render($res, '@stats/index.twig', [
            'title'      => 'Stats',
            'user_count' => $userCount,
        ]);
    },
];
```

Template at `admin_modules/stats/templates/index.twig`:

```twig
{% extends "layout.twig" %}

{% block content %}
<h1 class="mb-4">Stats</h1>

<div class="card border-0 shadow-sm text-center" style="max-width: 200px">
    <div class="card-body py-4">
        <div class="display-4 fw-bold text-primary">{{ user_count }}</div>
        <div class="text-muted mt-1">Total users</div>
    </div>
</div>

<div class="mt-3">
    <a href="/admin" class="btn btn-outline-secondary btn-sm">← Back to Admin</a>
</div>
{% endblock %}
```

---

## Page with a form (render + handle)

Add a `handle` key for `POST` processing on the same path.

```php
<?php
return [
    'slug'        => 'settings',
    'title'       => 'App Settings',
    'icon'        => '⚙️',
    'description' => 'Global application configuration.',

    'render' => function ($req, $res, $view, $db, $c) {
        $stmt = $db->query('SELECT key, value FROM app_settings');
        $settings = array_column($stmt->fetchAll(), 'value', 'key');

        return $view->render($res, '@settings/form.twig', [
            'title'    => 'App Settings',
            'settings' => $settings,
        ]);
    },

    'handle' => function ($req, $res, $view, $db, $c) {
        $body = (array) $req->getParsedBody();

        $stmt = $db->prepare('INSERT INTO app_settings (key, value)
                              VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');

        foreach (['site_name', 'maintenance_mode'] as $key) {
            $stmt->execute([$key, $body[$key] ?? '']);
        }

        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    },
];
```

---

## Multi-route module (CRUD)

Use `routes` when the module needs more than one URL. Each key is `METHOD /path` — routes are registered under `/admin/{slug}`.

```php
<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return [
    'slug'        => 'announcements',
    'title'       => 'Announcements',
    'icon'        => '📢',
    'description' => 'Manage site-wide announcements.',
    'order'       => 20,

    'routes' => [

        // List — GET /admin/announcements
        'GET /' => function (Request $req, Response $res, array $args, $view, $db, $c): Response {
            $rows = $db->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();
            return $view->render($res, '@announcements/list.twig', ['announcements' => $rows]);
        },

        // Create form — GET /admin/announcements/new
        'GET /new' => function ($req, $res, $args, $view, $db, $c): Response {
            return $view->render($res, '@announcements/form.twig');
        },

        // Create submit — POST /admin/announcements
        'POST /' => function ($req, $res, $args, $view, $db, $c): Response {
            $body = (array) $req->getParsedBody();
            $db->prepare('INSERT INTO announcements (body) VALUES (?)')->execute([$body['body'] ?? '']);
            return $res->withHeader('Location', '/admin/announcements')->withStatus(302);
        },

        // Delete — POST /admin/announcements/{id}/delete
        'POST /{id}/delete' => function ($req, $res, array $args, $view, $db, $c): Response {
            $db->prepare('DELETE FROM announcements WHERE id = ?')->execute([$args['id']]);
            return $res->withHeader('Location', '/admin/announcements')->withStatus(302);
        },
    ],
];
```

Routes are prefixed with `/admin/{slug}` automatically:

| Key | URL |
|-----|-----|
| `GET /` | `GET /admin/announcements` |
| `GET /new` | `GET /admin/announcements/new` |
| `POST /` | `POST /admin/announcements` |
| `POST /{id}/delete` | `POST /admin/announcements/{id}/delete` |

---

## Templates

### Namespace

Module templates live in `admin_modules/{slug}/templates/` and are accessed via Twig's `@slug` namespace:

```twig
{# renders admin_modules/stats/templates/index.twig #}
{{ include('@stats/index.twig') }}
```

From PHP:
```php
$view->render($res, '@stats/index.twig', ['user_count' => 42]);
```

### Extending the layout

Use `layout.twig` to wrap your template in the dashboard shell:

```twig
{% extends "layout.twig" %}

{% block title %}Stats{% endblock %}

{% block content %}
<h1>Stats</h1>
...
{% endblock %}
```

---

## Handler signatures

All handlers receive the same arguments as regular module handlers:

```php
function (
    \Psr\Http\Message\ServerRequestInterface $request,
    \Psr\Http\Message\ResponseInterface      $response,
    array                                    $args,       // route placeholders — routes modules only
    \Slim\Views\Twig                         $view,
    \PDO                                     $db,
    \Psr\Container\ContainerInterface        $container,
): \Psr\Http\Message\ResponseInterface
```

For `render` and `handle` (simple modules) `$args` is not passed — the signature starts with `$request, $response, $view, $db, $container`.

---

## Accessing services

Every handler receives `$container` as its last argument:

```php
use Psr\Log\LoggerInterface;
use RafalMasiarek\DashboardKit\Flash;

'render' => function ($req, $res, $view, $db, $container) {
    $logger = $container->get(LoggerInterface::class);
    $flash  = $container->get(Flash::class);

    $logger->info('admin.stats.viewed');

    return $view->render($res, '@stats/index.twig');
},
```

---

## CSRF in forms

Admin modules are part of the `/admin` group which applies `CsrfMiddleware` automatically. Any form that submits `POST` must include the CSRF field:

```twig
<form method="POST" action="/admin/announcements">
    {{ csrf_field('admin-announcements-create') }}
    <input name="body" type="text">
    <button type="submit">Save</button>
</form>
```

`csrf_field(container)` renders a hidden `_csrf` field and a hidden `_csrf_container` field. The container string scopes the token — use a unique value per form to prevent token reuse across forms.

---

## Admin landing page

The `/admin` page renders a card for every registered admin module automatically. The card uses:

| Module key | Where it appears |
|------------|-----------------|
| `title`    | Card heading |
| `icon`     | Large emoji above the heading |
| `description` | Subtitle text below the heading |
| `order`    | Sort order among cards (lower = first) |

The built-in **Users** card is always rendered first regardless of `order`.

---

## Reacting to admin actions via hooks

The built-in user management routes emit hooks after every action. Admin modules can listen for these events to trigger side-effects (notifications, audit logging, cache invalidation, etc.).

Register listeners in `public/index.php` **before** `$dashboard->run()`:

```php
use AuthKit\User;

// Fired after an admin suspends a user.
$dashboard->on('user_suspended', function (User $target, User $admin) {
    // $target — the user who was suspended
    // $admin  — the admin who performed the action
});

// Fired after an admin edits a user's account fields.
$dashboard->on('user_updated', function (User $target, array $changedFields, User $admin) {
    // $changedFields — list of field names that changed, e.g. ['email', 'role']
});

// Fired after an admin permanently deletes a user.
$dashboard->on('user_deleted', function (User $target, User $admin) {
    // do cleanup, notify, etc.
});
```

See [hooks.md](hooks.md) for all supported events and their signatures. Note that `on()` **replaces** any previously registered listener for that event — registering twice keeps only the second handler.

---

## Module keys reference

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `slug` | string | **yes** | Unique identifier, URL segment (`/admin/slug`) |
| `title` | string | no | Card heading and page title |
| `icon` | string | no | Emoji shown on the landing page card |
| `description` | string | no | Card subtitle on the landing page |
| `order` | int | no | Card sort order, lowest first. Default `99` |
| `render` | callable | no | Handles `GET` for simple modules |
| `handle` | callable | no | Handles `POST` for simple modules |
| `routes` | array | no | Map of `METHOD /path` → handler for multi-route modules |
