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
