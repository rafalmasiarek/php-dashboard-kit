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
