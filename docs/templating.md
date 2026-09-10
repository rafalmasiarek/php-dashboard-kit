# Templating

dashboard-kit uses Twig with a two-layer template resolution order: your application's `templates/` directory is searched first, then the package's built-in templates. Placing a file with the same name in your app's templates directory replaces the built-in one entirely.

## Template resolution order

```
1. {templates_dir}/               ← your app (configured via templates_dir in Dashboard::create())
2. vendor/rafalmasiarek/dashboard-kit/templates/   ← built-in fallback
```

Both paths are also accessible under the `@dashboard-kit` Twig namespace, which lets your templates extend or include core templates without infinite recursion.

## Built-in templates

| Template | Route | Description |
|---|---|---|
| `layout.twig` | all pages | Base HTML shell — navbar, flash messages, scripts |
| `home.twig` | `GET /` | Auth-aware landing page |
| `login.twig` | `GET/POST /login` | Login form |
| `register.twig` | `GET/POST /register` | Registration form |
| `settings.twig` | `GET /settings` | User settings |
| `admin/index.twig` | `GET /admin` | Admin panel |
| `admin/users.twig` | `GET /admin/users` | User list |
| `errors/error.twig` | error fallback | Generic error page |

---

## Layout blocks

`layout.twig` exposes six overridable blocks. Override any subset — omitted blocks fall back to the built-in content.

| Block | Default content | Typical use |
|---|---|---|
| `{% block head %}` | *(empty)* | Extra `<link>` / `<meta>` tags |
| `{% block navbar %}` | Full Bootstrap navbar with module links and user dropdown | Replace or remove the navbar |
| `{% block header %}` | *(empty)* | Page-level hero/banner below the navbar |
| `{% block content %}` | *(empty)* | Main page body — every page template fills this |
| `{% block footer %}` | *(empty)* | Site-wide footer |
| `{% block scripts %}` | *(empty)* | Extra `<script>` tags before `</body>` |

### Available Twig globals in all templates

| Global | Type | Description |
|---|---|---|
| `app_name` | string | Value of `app.name` from config |
| `auth` | Auth | AuthKit instance — `auth.isLoggedIn()`, `auth.getUser()` |
| `modules` | array | Registered modules (used by navbar) |
| `flash` | Flash | Flash message bag — `flash.get()` |
| `current_path` | string | Current request URI path |
| `registration_enabled` | bool | Whether `/register` is active |
| `password_reset_enabled` | bool | Whether `/forgot-password` is active |

---

## Overriding the home page

Place `home.twig` in your app's `templates/` directory — it takes precedence over the built-in:

```twig
{# templates/home.twig #}
{% extends "layout.twig" %}

{% block title %}Welcome{% endblock %}

{% block content %}
{% if auth.isLoggedIn() %}
    <h2>Hello, {{ auth.getUser().get('first_name') ?: auth.getUser().get('email') }}</h2>
{% else %}
    <h1>{{ app_name }}</h1>
    <a href="/login" class="btn btn-primary">Log in</a>
{% endif %}
{% endblock %}
```

---

## Overriding one layout section

Extend `layout.twig` and override only the blocks you need. All other blocks keep the default built-in output.

### Custom navbar

```twig
{# templates/layout.twig #}
{% extends "@dashboard-kit/layout.twig" %}

{% block navbar %}
<nav class="navbar navbar-light bg-white border-bottom mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/">{{ app_name }}</a>
        {% if auth.isLoggedIn() %}
        <a href="/logout" class="btn btn-sm btn-outline-secondary">Logout</a>
        {% else %}
        <a href="/login" class="btn btn-sm btn-primary">Login</a>
        {% endif %}
    </div>
</nav>
{% endblock %}
```

Note the `{% extends "@dashboard-kit/layout.twig" %}` — using the `@dashboard-kit` namespace avoids infinite recursion when your file is also named `layout.twig`.

### Adding a site footer

```twig
{# templates/layout.twig #}
{% extends "@dashboard-kit/layout.twig" %}

{% block footer %}
<footer class="border-top mt-5 py-4 text-center text-muted small">
    &copy; {{ "now"|date("Y") }} {{ app_name }}
</footer>
{% endblock %}
```

### Extra styles in `<head>`

```twig
{# templates/layout.twig #}
{% extends "@dashboard-kit/layout.twig" %}

{% block head %}
<link rel="stylesheet" href="/assets/app.css">
{% endblock %}
```

---

## Replacing the layout entirely

Drop a `layout.twig` in your templates directory with no `{% extends %}` — it becomes the only layout, the built-in is ignored completely.

```twig
{# templates/layout.twig — full replacement #}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{% block title %}{% endblock %} — {{ app_name }}</title>
    <link rel="stylesheet" href="/assets/app.css">
    {% block head %}{% endblock %}
</head>
<body>
    <header>
        <a href="/">{{ app_name }}</a>
        {% if auth.isLoggedIn() %}<a href="/logout">Logout</a>{% endif %}
    </header>

    {% for type, messages in flash.get() %}
        {% for message in messages %}
        <div class="flash flash--{{ type }}">{{ message }}</div>
        {% endfor %}
    {% endfor %}

    <main>
        {% block content %}{% endblock %}
    </main>

    <footer>Footer</footer>
    {% block scripts %}{% endblock %}
</body>
</html>
```

All built-in page templates (`login.twig`, `register.twig`, `settings.twig`, etc.) extend `layout.twig` and fill `{% block content %}`. They will automatically use your replacement layout because the resolution order puts your templates first.

---

## Overriding other built-in pages

The same pattern applies to any built-in template. Place a file with the same path in your templates directory:

```
templates/
├── layout.twig        ← overrides layout
├── home.twig          ← overrides home page
├── login.twig         ← overrides login form
├── register.twig      ← overrides registration form
└── errors/
    └── error.twig     ← overrides generic error page
```

To extend a built-in page rather than replace it, use the `@dashboard-kit` namespace:

```twig
{# templates/login.twig #}
{% extends "@dashboard-kit/login.twig" %}

{% block scripts %}
    {{ parent() }}
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
{% endblock %}
```

`{{ parent() }}` preserves the block's original content before appending your additions.
