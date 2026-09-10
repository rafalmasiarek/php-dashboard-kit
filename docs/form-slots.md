# Form slots

Form slots let plugins inject HTML into the built-in login and register forms without modifying core templates. Each slot is identified by a **form name** and a **slot name**. Content can be a plain HTML string or a callable evaluated at render time.

## Built-in slots

| Form | Slot | Position |
|------|------|----------|
| `login` | `form_fields` | Inside `<form>`, directly before the submit button |
| `login` | `scripts` | In `{% block scripts %}`, outside the form |
| `register` | `form_fields` | Inside `<form>`, directly before the submit button |
| `register` | `scripts` | At the end of `{% block scripts %}` |

---

## Registering a slot

Resolve `FormSlotRegistry` from the container and call `register()` before `$dashboard->run()`. This is typically done inside an addon's `register()` method.

```php
use rafalmasiarek\DashboardKit\Extension\FormSlotRegistry;

$registry = $container->get(FormSlotRegistry::class);

// Static HTML string
$registry->register('login', 'form_fields', '<div class="g-recaptcha mb-3" data-sitekey="..."></div>');

// External script in the scripts slot
$registry->register('login', 'scripts', '<script src="https://example.com/widget.js" async defer></script>');
```

Multiple registrations for the same slot are accumulated and rendered in `order` sequence (ascending).

```php
$registry->register('login', 'form_fields', $htmlA, order: 10);
$registry->register('login', 'form_fields', $htmlB, order: 20); // rendered after $htmlA
```

---

## Callable content

When the slot content depends on runtime state (session, request context), pass a `callable(): string` instead of a string. It is called at render time, not at registration time.

```php
// Show only after 3 failed login attempts in the current session
$registry->register('login', 'form_fields', static function (): string {
    $fails = (int) ($_SESSION['login_fails'] ?? 0);
    if ($fails < 3) {
        return '';
    }
    return '<div class="g-recaptcha mb-3" data-sitekey="..."></div>';
});
```

The callable receives no arguments — close over whatever state you need.

---

## Twig function

The `form_slot(form, slot)` Twig function is available in all templates. It renders all registered snippets for the given slot, sorted by order, and returns the result as safe (unescaped) HTML.

```twig
{# inside login.twig — already present in core template #}
{{ form_slot('login', 'form_fields') }}
<button class="btn btn-primary w-100">Login</button>
```

When no snippets are registered for a slot, the function returns an empty string and renders nothing.

---

## Rendering order

All entries for a given `(form, slot)` are sorted by `order` (ascending) before concatenation. Default `order` is `100`. Use lower numbers to inject before the default position.

```php
$registry->register('login', 'form_fields', $tosCheckbox,  order: 10);  // first
$registry->register('login', 'form_fields', $captchaWidget, order: 50);  // second
```

---

## PHP-DI note

When storing a callable via `$container->set()` in PHP-DI, plain closures are treated as factory definitions — PHP-DI tries to resolve the closure's parameters from the container, not from your call site. If your addon needs to update `auth.before_login` or `auth.before_register`, wrap the hook callable in an outer factory:

```php
// Correct — outer fn() is the PHP-DI factory, inner function is the hook callable
$container->set('auth.before_login', fn() => function ($request) use ($verifier) {
    return $verifier->check($request) ? null : 'CAPTCHA failed.';
});

// Wrong — PHP-DI calls this with DI\Container as $request
$container->set('auth.before_login', function ($request) use ($verifier) { ... });
```

This does not affect `FormSlotRegistry::register()`, which stores values directly without going through the DI container.

---

## Example — `dashboard-kit-recaptcha`

The `dashboard-kit-recaptcha` addon demonstrates the full pattern: callable slots for conditional rendering, chained `before_login` for server-side verification, and the `login_failed` hook for failure tracking.

```php
use rafalmasiarek\DashboardKitRecaptcha\RecaptchaAddon;

RecaptchaAddon::register($dashboard->getApp(), $dashboard->getContainer(), [
    'site_key'   => 'your-site-key',
    'secret_key' => 'your-secret-key',
    'login'      => ['mode' => 'x_failed', 'threshold' => 3],
    'register'   => true,
]);
```

Configuration:

| Key | Type | Description |
|-----|------|-------------|
| `login.mode` | `'always'` \| `'x_failed'` | When to show captcha on the login form |
| `login.threshold` | int | Failures before captcha appears in `x_failed` mode (default: 3) |
| `register` | bool | Show captcha on the register form (default: `false`) |
