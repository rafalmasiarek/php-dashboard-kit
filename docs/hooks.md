# Hooks

Hooks let you react to dashboard events without modifying the core code. Every auth and admin action fires a named event — you attach a callable to it in `public/index.php` before calling `run()`.

## Registering a listener

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);

$dashboard->on('register', function (\AuthKit\User $user) {
    // runs after a new account is created
});

$dashboard->run();
```

## Replace semantics

`on()` **replaces** any previously registered listener for that event. This is intentional: the dashboard ships with default flash-message handlers for `register` and `logout`. Calling `on()` for either of those events replaces the default — you get one flash, not two.

```php
// Default handler adds "Account created. You can now log in."
// Your handler replaces it entirely:
$dashboard->on('register', function (\AuthKit\User $user) use ($container) {
    $container->get(\rafalmasiarek\DashboardKit\Flash::class)
        ->add('info', 'Check your email to activate your account.');
});
```

If you need the default flash *and* additional behaviour, add it explicitly in your handler.

---

## Default handlers

Two events have default handlers registered by the dashboard. Both add a flash message and nothing else.

| Event | Default flash |
|-------|---------------|
| `register` | `success` — "Account created. You can now log in." |
| `logout` | `success` — "You have been logged out." |

All other events have no default handler — nothing fires unless you register one.

---

## Supported events

### Auth events

#### `register` — new account created

```php
$dashboard->on('register', function (\AuthKit\User $user): void {
    // $user — the newly registered user
});
```

Fires after `Auth::register()` succeeds and before the redirect to `/login`.

#### `register_failed` — registration attempt rejected

```php
$dashboard->on('register_failed', function (string $email, string $errorMessage, string $ip): void {
    // $email        — submitted email address
    // $errorMessage — reason for rejection (e.g. "Email already taken.")
    // $ip           — client IP from REMOTE_ADDR
});
```

Fires when `Auth::register()` throws (duplicate email, validation failure). Use this for registration rate limiting or abuse detection.

#### `login` — successful login

```php
$dashboard->on('login', function (\AuthKit\User $user): void {
    // $user — the authenticated user
});
```

Fires after credentials are validated, before the redirect to `/` (or the `?from=` path).

#### `login_failed` — login attempt rejected

```php
$dashboard->on('login_failed', function (string $email, string $ip): void {
    // $email — submitted email address
    // $ip    — client IP from REMOTE_ADDR
});
```

Fires after a failed credential check. Use this to track failure counts, trigger rate limiting, or log suspicious activity. Used by `dashboard-kit-recaptcha` to implement the `x_failed` threshold mode.

#### `logout` — session ended

```php
$dashboard->on('logout', function (\AuthKit\User $user): void {
    // $user — the user who logged out
});
```

Fires before `Auth::logout()` destroys the session.

---

### Password reset events

#### `password_reset_requested` — reset email requested

```php
$dashboard->on('password_reset_requested', function (string $email, string $ip): void {
    // $email — the address the reset was requested for (may not exist in the DB)
    // $ip    — client IP
});
```

Fires regardless of whether the email exists, to prevent user enumeration. Use for rate limiting or alerting on suspicious reset activity.

#### `password_reset_completed` — password changed via reset link

```php
$dashboard->on('password_reset_completed', function (\AuthKit\User $user, string $ip): void {
    // $user — account whose password was reset
    // $ip   — client IP
});
```

Fires after the new password is saved and the reset token invalidated.

---

### Settings events

#### `password_changed` — password updated via settings

```php
$dashboard->on('password_changed', function (\AuthKit\User $user): void {
});
```

#### `email_changed` — email address updated via settings

```php
$dashboard->on('email_changed', function (\AuthKit\User $user, string $oldEmail): void {
    // $oldEmail — the address before the change
});
```

#### `profile_updated` — custom profile fields updated via settings

```php
$dashboard->on('profile_updated', function (\AuthKit\User $user, array $updatedFields): void {
    // $updatedFields — map of field names to new values, e.g. ['first_name' => 'Alice']
});
```

---

### Admin user management events

#### `user_created` — admin created a new account

```php
$dashboard->on('user_created', function (\AuthKit\User $target, \AuthKit\User $admin): void {
    // $target — the newly created user
    // $admin  — admin who performed the action
});
```

Fires only from the admin panel (`/admin/users/create`), not from the public `/register` route. For public registration use the `register` event.

#### `user_suspended` — admin suspended an account

```php
$dashboard->on('user_suspended', function (\AuthKit\User $target, \AuthKit\User $admin): void {
    // $target — suspended user
    // $admin  — admin who performed the action
});
```

#### `user_unsuspended` — admin lifted a suspension

```php
$dashboard->on('user_unsuspended', function (\AuthKit\User $target, \AuthKit\User $admin): void {
});
```

#### `user_updated` — admin edited a user's account fields

```php
$dashboard->on('user_updated', function (\AuthKit\User $target, array $changedFields, \AuthKit\User $admin): void {
    // $changedFields — list of field names that were changed, e.g. ['email', 'role', 'active']
});
```

#### `user_deleted` — admin permanently deleted an account

```php
$dashboard->on('user_deleted', function (\AuthKit\User $target, \AuthKit\User $admin): void {
    // $target — the deleted user (fetched before deletion)
});
```

---

### Mail events

#### `mail_sent` / `mail_failed`

Fired by `Mailer::send()` after every delivery attempt. Use these for monitoring, alerting, or custom retry logic without touching the Mailer.

```php
use rafalmasiarek\DashboardKit\Mail\MailMessage;

$dashboard->on('mail_sent', function (MailMessage $message): void {
    // $message->getToEmail(), ->getSubject() available
});

$dashboard->on('mail_failed', function (MailMessage $message, \Throwable $e): void {
    // $e contains the delivery error
    // hook fires before the exception is re-thrown by Mailer
});
```

---

## Intercepting form submissions

Two callables run before credentials are checked, allowing a plugin to block a login or registration attempt before the database is hit. Unlike hooks, these return a value — `null` to pass, a string to block with that message.

### `before_login`

Called on every `POST /login` before credentials are verified.

**Via config:**
```php
Dashboard::create(__DIR__ . '/../', [
    'before_login' => function (\Psr\Http\Message\ServerRequestInterface $request): ?string {
        $body = (array) $request->getParsedBody();
        // return null to allow, string to block with that error
        return isset($body['agreed']) ? null : 'You must accept the terms.';
    },
]);
```

**Via container (overrides config, useful from addon code):**
```php
// Wrap the existing callable so it remains in the chain
$existing = $dashboard->getContainer()->get('auth.before_login');
$dashboard->getContainer()->set('auth.before_login', fn() => function ($request) use ($existing) {
    if ($existing !== null && ($err = $existing($request)) !== null) {
        return $err;
    }
    // ... additional check
    return null;
});
```

### `before_register`

Same signature as `before_login`, runs before `POST /register`.

```php
Dashboard::create(__DIR__ . '/../', [
    'before_register' => function (\Psr\Http\Message\ServerRequestInterface $request): ?string {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        return isRateLimited($ip) ? 'Too many registration attempts.' : null;
    },
]);
```

> `dashboard-kit-recaptcha` uses both `before_login` and `before_register` internally — it chains onto any existing callable so user-defined checks are not lost.

---

## Accessing services inside a hook

Capture the container before `run()` and use it inside your closures:

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);
$container = $dashboard->getContainer();

$dashboard->on('register', function (\AuthKit\User $user) use ($container) {
    $container->get('logger.audit')
        ->info('user.welcome_sent', ['email' => $user->getEmail()]);

    $container->get(\rafalmasiarek\DashboardKit\Flash::class)
        ->add('info', 'Welcome! Check your email.');
});

$dashboard->run();
```

---

## Event reference

| Event | Arguments | Default handler |
|-------|-----------|-----------------|
| `register` | `User $user` | flash "Account created. You can now log in." |
| `register_failed` | `string $email, string $errorMessage, string $ip` | — |
| `login` | `User $user` | — |
| `login_failed` | `string $email, string $ip` | — |
| `logout` | `User $user` | flash "You have been logged out." |
| `password_reset_requested` | `string $email, string $ip` | — |
| `password_reset_completed` | `User $user, string $ip` | — |
| `password_changed` | `User $user` | — |
| `email_changed` | `User $user, string $oldEmail` | — |
| `profile_updated` | `User $user, array $updatedFields` | — |
| `user_created` | `User $target, User $admin` | — |
| `user_suspended` | `User $target, User $admin` | — |
| `user_unsuspended` | `User $target, User $admin` | — |
| `user_updated` | `User $target, string[] $changedFields, User $admin` | — |
| `user_deleted` | `User $target, User $admin` | — |
| `mail_sent` | `MailMessage $message` | — |
| `mail_failed` | `MailMessage $message, \Throwable $e` | — |
