# Modules

Modules are self-contained feature units that live in your application's `modules/` directory. Each module is a folder containing a `module.php` file that returns a PHP array. Dashboard Kit discovers them automatically — no registration step required.

## Directory structure

```
your-app/
└── modules/
    └── orders/
        ├── module.php          # required — module definition
        └── templates/          # optional — Twig templates
            ├── list.twig
            ├── detail.twig
            └── form.twig
```

The `modules/` directory defaults to `{rootDir}/modules`. Override it in config:

```php
Dashboard::create(__DIR__ . '/../', [
    'modules_dir' => __DIR__ . '/../modules',
]);
```

## module.php structure

`module.php` must return an array. The only required key is `slug`.

```php
<?php
return [
    // --- Identity ---
    'slug'    => 'orders',    // URL prefix and unique identifier
    'title'   => 'Orders',    // displayed in the navbar
    'icon'    => '🛒',        // emoji or HTML shown next to title
    'order'   => 10,          // navbar sort order (lower = first)

    // --- Visibility ---
    'navbar'  => true,        // show in the left navigation bar

    // --- Access ---
    'auth_required' => true,  // see "Access control" section

    // --- Handlers (choose one style) ---
    'render'  => fn(...) => ...,   // simple page
    'handle'  => fn(...) => ...,   // form submission for simple page
    // OR
    'routes'  => [...],            // multi-route (CRUD etc.)
    // OR
    'api'     => [...],            // JSON API endpoints
];
```

## Handler signatures

All handlers receive the same arguments:

```php
function (
    \Psr\Http\Message\ServerRequestInterface $request,
    \Psr\Http\Message\ResponseInterface      $response,
    array                                    $args,       // route placeholders, e.g. ['id' => '5']
    \Slim\Views\Twig                         $view,
    \PDO                                     $db,
    \Psr\Container\ContainerInterface        $container,
): \Psr\Http\Message\ResponseInterface
```

For `render` and `handle` (simple modules) `$args` is not passed — the signature starts with `$request, $response, $view, $db, $container`.

---

## Simple page module

A module with only a `render` key handles `GET` requests.

```php
<?php
return [
    'slug'          => 'reports',
    'title'         => 'Reports',
    'icon'          => '📊',
    'order'         => 5,
    'navbar'        => true,
    'auth_required' => true,

    'render' => function ($req, $res, $view, $db, $c) {
        $rows = $db->query('SELECT * FROM orders WHERE status = "complete"')->fetchAll();
        return $view->render($res, '@reports/index.twig', ['orders' => $rows]);
    },
];
```

## Page with a form (render + handle)

Add a `handle` key for `POST` processing on the same path.

```php
<?php
return [
    'slug'          => 'contact',
    'title'         => 'Contact',
    'navbar'        => true,
    'auth_required' => false,

    'render' => function ($req, $res, $view, $db, $c) {
        return $view->render($res, '@contact/form.twig');
    },

    'handle' => function ($req, $res, $view, $db, $c) {
        $body    = (array) $req->getParsedBody();
        $message = trim($body['message'] ?? '');

        if ($message === '') {
            return $view->render($res, '@contact/form.twig', ['error' => 'Message is required.']);
        }

        $stmt = $db->prepare('INSERT INTO messages (body, created_at) VALUES (?, NOW())');
        $stmt->execute([$message]);

        return $res->withHeader('Location', '/contact')->withStatus(302);
    },
];
```

---

## Multi-route module (CRUD)

Use `routes` when the module needs more than one URL (list, detail, create, edit, delete). Each key is `METHOD /path`.

```php
<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return [
    'slug'          => 'notes',
    'title'         => 'Notes',
    'icon'          => '📝',
    'order'         => 2,
    'navbar'        => true,
    'auth_required' => true,

    'routes' => [

        // List
        'GET /' => function (Request $req, Response $res, array $args, $view, PDO $db, $c): Response {
            $notes = $db->query('SELECT * FROM notes ORDER BY created_at DESC')->fetchAll();
            return $view->render($res, '@notes/list.twig', ['notes' => $notes]);
        },

        // Create form
        'GET /new' => function ($req, $res, $args, $view, $db, $c): Response {
            return $view->render($res, '@notes/form.twig');
        },

        // Create submit
        'POST /' => function ($req, $res, $args, $view, PDO $db, $c): Response {
            $body = trim((string) (((array) $req->getParsedBody())['body'] ?? ''));
            if ($body !== '') {
                $db->prepare('INSERT INTO notes (body) VALUES (?)')->execute([$body]);
            }
            return $res->withHeader('Location', '/notes')->withStatus(302);
        },

        // Detail
        'GET /{id}' => function ($req, $res, array $args, $view, PDO $db, $c): Response {
            $stmt = $db->prepare('SELECT * FROM notes WHERE id = ?');
            $stmt->execute([$args['id']]);
            $note = $stmt->fetch();
            if (!$note) {
                return $view->render($res->withStatus(404), '@notes/not-found.twig', ['id' => $args['id']]);
            }
            return $view->render($res, '@notes/detail.twig', ['note' => $note]);
        },

        // Edit form
        'GET /{id}/edit' => function ($req, $res, array $args, $view, PDO $db, $c): Response {
            $stmt = $db->prepare('SELECT * FROM notes WHERE id = ?');
            $stmt->execute([$args['id']]);
            $note = $stmt->fetch();
            return $view->render($res, '@notes/form.twig', ['note' => $note]);
        },

        // Edit submit
        'POST /{id}/edit' => function ($req, $res, array $args, $view, PDO $db, $c): Response {
            $body = trim((string) (((array) $req->getParsedBody())['body'] ?? ''));
            $db->prepare('UPDATE notes SET body = ? WHERE id = ?')->execute([$body, $args['id']]);
            return $res->withHeader('Location', '/notes/' . $args['id'])->withStatus(302);
        },

        // Delete
        'POST /{id}/delete' => function ($req, $res, array $args, $view, PDO $db, $c): Response {
            $db->prepare('DELETE FROM notes WHERE id = ?')->execute([$args['id']]);
            return $res->withHeader('Location', '/notes')->withStatus(302);
        },
    ],
];
```

Routes are prefixed with `/{slug}` automatically. The full URLs become:

| Key | URL |
|-----|-----|
| `GET /` | `GET /notes` |
| `GET /new` | `GET /notes/new` |
| `POST /` | `POST /notes` |
| `GET /{id}` | `GET /notes/{id}` |
| `POST /{id}/delete` | `POST /notes/{id}/delete` |

---

## JSON API module

Use `api` to expose JSON endpoints under `/api/{slug}`.

```php
<?php
return [
    'slug'          => 'stats',
    'auth_required' => true,  // also applied to api endpoints
    'navbar'        => false,

    'api' => [
        'GET /summary' => function ($req, array $args, PDO $db, $container): array {
            $total = $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            return ['total_orders' => (int) $total];
        },

        'GET /by-status' => function ($req, array $args, PDO $db, $container): array {
            return $db->query('SELECT status, COUNT(*) as n FROM orders GROUP BY status')->fetchAll();
        },
    ],
];
```

API handler signature differs slightly — no `$response` or `$view`, return an array:

```php
function (
    \Psr\Http\Message\ServerRequestInterface $request,
    array                                    $args,
    \PDO                                     $db,
    \Psr\Container\ContainerInterface        $container,
): array
```

The array is JSON-encoded automatically with `Content-Type: application/json`.

Full URLs: `GET /api/stats/summary`, `GET /api/stats/by-status`.

---

## Access control

### Require login

```php
'auth_required' => true,   // default — redirects to /login if unauthenticated
'auth_required' => false,  // public — no login required
```

### Require a specific role

```php
'auth_required' => 'admin',              // only users with role = admin
'auth_required' => ['admin', 'editor'],  // multiple roles accepted
```

Users without the required role are redirected to `/` with no error message.

---

## Templates

### Namespace

Module templates live in `modules/{slug}/templates/` and are accessed via Twig's `@slug` namespace:

```twig
{# renders modules/notes/templates/list.twig #}
{% extends '@notes/list.twig' %}

{# renders modules/notes/templates/form.twig #}
{{ include('@notes/form.twig') }}
```

From PHP:
```php
$view->render($res, '@notes/list.twig', ['notes' => $notes]);
```

### Extending the layout

Use `layout.twig` to wrap your template in the dashboard shell (navbar, flash messages, etc.):

```twig
{% extends 'layout.twig' %}

{% block title %}Notes{% endblock %}

{% block content %}
<div class="container-fluid py-4">
    <h1>Notes</h1>

    {% for note in notes %}
        <div class="card mb-2">
            <div class="card-body">{{ note.body }}</div>
        </div>
    {% endfor %}

    <a href="/notes/new" class="btn btn-primary">New note</a>
</div>
{% endblock %}
```

### Global Twig variables

These are available in every template without passing them explicitly:

| Variable | Type | Value |
|----------|------|-------|
| `app_name` | string | Value of `app_name` config key |
| `user_fields` | array | Configured custom profile fields |
| `flash` | Flash | Flash message bag |
| `auth` | Auth | AuthKit instance (`auth.getUser()` etc.) |
| `modules` | array | Navbar-visible modules |
| `current_path` | string | Current request path |
| `_user` | User\|null | Shortcut set in layout — current authenticated user |

---

## Module keys reference

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `slug` | string | **yes** | Unique identifier, URL prefix (`/slug`) |
| `title` | string | no | Label in navbar and page titles |
| `icon` | string | no | Emoji or HTML shown before title in navbar |
| `order` | int | no | Navbar sort order, lowest first. Default `99` |
| `navbar` | bool | no | Show in left navigation. Default `false` |
| `path` | string | no | Override navbar link URL. Default `/{slug}` |
| `auth_required` | bool\|string\|string[] | no | Access control. Default `true` |
| `render` | callable | no | Handles `GET` for simple modules |
| `handle` | callable | no | Handles `POST` for simple modules |
| `routes` | array | no | Map of `METHOD /path` → handler for CRUD modules |
| `api` | array | no | Map of `METHOD /path` → handler for JSON API modules |

---

## Accessing services from a handler

Every handler receives `$container` as its last argument. Use it to resolve any service registered in the DI container.

```php
use Psr\Log\LoggerInterface;
use RafalMasiarek\DashboardKit\Flash;
use AuthKit\Auth;

'routes' => [
    'POST /{id}/delete' => function ($req, $res, $args, $view, $db, $container): Response {
        // Flash message
        $container->get(Flash::class)->add('success', 'Record deleted.');

        // Logger — writes to app.log
        $container->get(LoggerInterface::class)->info('record.deleted', ['id' => $args['id']]);

        // Current authenticated user
        $user = $container->get(Auth::class)->getUser();

        return $res->withHeader('Location', '/items')->withStatus(302);
    },
],
```

See [logging.md](logging.md) for full details on using the logger from modules.

---

## Overriding built-in templates

Place a file with the same relative path in your app's `templates/` directory. User templates are loaded first, so they shadow the package's built-ins.

```
your-app/
└── templates/
    └── layout.twig     # overrides vendor package layout
    └── login.twig      # overrides default login form
    └── errors/
        └── 500.twig    # custom 500 error page
```

Configure the path:

```php
Dashboard::create(__DIR__ . '/../', [
    'templates_dir' => __DIR__ . '/../templates',
]);
```
