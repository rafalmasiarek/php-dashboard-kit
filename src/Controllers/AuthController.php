<?php

namespace rafalmasiarek\DashboardKit\Controllers;

use AuthKit\Auth;
use AuthKit\Exception\AuthException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use rafalmasiarek\DashboardKit\Flash;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use rafalmasiarek\DashboardKit\Mail\Mailer;
use rafalmasiarek\DashboardKit\Mail\MailMessage;
use rafalmasiarek\DashboardKit\Utils\PasswordStrength;
use Slim\Views\Twig;

/**
 * Handles login, registration and logout flows.
 *
 * @package rafalmasiarek\DashboardKit\Controllers
 */
class AuthController
{
    /**
     * @param Twig          $view               Twig rendering engine.
     * @param Auth          $auth               AuthKit authentication service.
     * @param Flash         $flash              Flash message store.
     * @param array<string, array{label: string, type: string, max: int, required: bool}> $userFields Extra profile fields shown on the register form.
     * @param HookRegistry  $hooks              Event hook registry.
     * @param AuditLog      $audit              Audit logger.
     * @param PDO           $db                 Database connection — used to assign UUID on registration.
     * @param array<string, mixed> $passwordStrength Resolved password-strength config (enabled, min_score, rules).
     * @param bool          $requireActivation  When true, sends an activation email after registration.
     * @param Mailer|null   $mailer             Mailer instance; required when requireActivation is true to send emails.
     * @param callable|null $beforeLogin        Called before credential check on POST /login.
     *                                          Signature: (Request): ?string — return null to pass, string to block with that error.
     *                                          Set via config key 'before_login' or $container->set('auth.before_login', callable).
     * @param callable|null $beforeRegister     Called before account creation on POST /register.
     *                                          Same signature as $beforeLogin.
     *                                          Set via config key 'before_register' or $container->set('auth.before_register', callable).
     * @param string        $dashboardUrlPrefix Full URL prefix prepended to all dashboard redirects (e.g. '/api/admin').
     *                                          Combines app.base_path and dashboard.prefix from config.
     */
    public function __construct(
        private readonly Twig         $view,
        private readonly Auth         $auth,
        private readonly Flash        $flash,
        private readonly array        $userFields,
        private readonly HookRegistry $hooks,
        private readonly AuditLog     $audit,
        private readonly PDO          $db,
        private readonly array        $passwordStrength,
        private readonly bool         $requireActivation  = false,
        private readonly ?Mailer      $mailer             = null,
        private readonly mixed        $beforeLogin        = null,
        private readonly mixed        $beforeRegister     = null,
        private readonly string       $dashboardUrlPrefix = '',
    ) {
    }

    /**
     * Renders the login form (GET) or processes credentials (POST).
     *
     * Reads a ?from= query parameter (GET) or a hidden 'from' field (POST) and
     * redirects there after successful login instead of the default '/'.
     * Only same-origin paths are accepted (must start with '/' but not '//').
     *
     * @param  Request  $request  Incoming request.
     * @param  Response $response PSR-7 response.
     * @return Response
     */
    public function login(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/')->withStatus(302);
        }

        if ($request->getMethod() === 'GET') {
            $from = (string) ($request->getQueryParams()['from'] ?? '');
            return $this->view->render($response, 'login.twig', ['from' => $from]);
        }

        $data        = (array) $request->getParsedBody();
        $from        = (string) ($data['from'] ?? $request->getQueryParams()['from'] ?? '');
        $loginTarget = $this->dashboardUrlPrefix . '/login' . ($from !== '' ? '?from=' . \urlencode($from) : '');

        if ($this->beforeLogin !== null) {
            $blockReason = ($this->beforeLogin)($request);
            if ($blockReason !== null) {
                $this->flash->add('danger', $blockReason);
                return $response->withHeader('Location', $loginTarget)->withStatus(302);
            }
        }

        try {
            $this->auth->login($data['email'] ?? '', $data['password'] ?? '');
        } catch (AuthException $e) {
            $this->audit->loginFailed($data['email'] ?? '', $e->getMessage());
            $this->hooks->emit('login_failed', $data['email'] ?? '');
            $this->flash->add('danger', $e->getMessage());
            return $response->withHeader('Location', $loginTarget)->withStatus(302);
        }

        $user = $this->auth->getUser();
        if ($user) {
            $this->audit->login($user);
            $this->hooks->emit('login', $user);
        }

        $redirect = ($from !== '' && \str_starts_with($from, '/') && !\str_starts_with($from, '//'))
            ? $from
            : $this->dashboardUrlPrefix . '/';

        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    /**
     * Renders the registration form (GET) or creates a new account (POST).
     *
     * On successful registration a UUID v4 is assigned to the new user row.
     * If password_strength is enabled the password is scored server-side before
     * the account is created; scores below min_score are rejected with an error.
     *
     * @param  Request  $request  Incoming request.
     * @param  Response $response PSR-7 response.
     * @return Response
     */
    public function register(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/')->withStatus(302);
        }

        if ($request->getMethod() === 'GET') {
            $input = (array) ($_SESSION['_register_input'] ?? []);
            unset($_SESSION['_register_input']);
            return $this->view->render($response, 'register.twig', ['input' => $input]);
        }

        $data = (array) $request->getParsedBody();

        $fail = function (string $message) use ($data, $response): Response {
            $safe = ['email' => $data['email'] ?? ''];
            foreach ($this->userFields as $name => $field) {
                $safe[$name] = $data[$name] ?? '';
            }
            $_SESSION['_register_input'] = $safe;
            $this->flash->add('danger', $message);
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/register')->withStatus(302);
        };

        if ($this->beforeRegister !== null) {
            $blockReason = ($this->beforeRegister)($request);
            if ($blockReason !== null) {
                return $fail($blockReason);
            }
        }

        if (($data['password'] ?? '') !== ($data['password_confirm'] ?? '')) {
            return $fail('Passwords do not match.');
        }

        if ($this->passwordStrength['enabled'] ?? false) {
            $score    = PasswordStrength::score($data['password'] ?? '', $this->passwordStrength['rules'] ?? []);
            $minScore = (int) ($this->passwordStrength['min_score'] ?? 2);
            if ($score < $minScore) {
                return $fail('Your password is too weak. Please choose a stronger one.');
            }
        }

        $customFields = [];
        foreach ($this->userFields as $name => $field) {
            $value = trim((string) ($data[$name] ?? ''));
            if ($field['required'] && $value === '') {
                return $fail($field['label'] . ' is required.');
            }
            if ($value !== '') {
                $customFields[$name] = $value;
            }
        }

        if (!$this->requireActivation) {
            $customFields['active'] = 1;
        }

        try {
            $user = $this->auth->register($data['email'] ?? '', $data['password'] ?? '', $customFields);
        } catch (AuthException $e) {
            $this->audit->registerFailed($data['email'] ?? '', $e->getMessage());
            $this->hooks->emit('register_failed', $data['email'] ?? '', $e->getMessage());
            return $fail($e->getMessage());
        }

        if ($user instanceof \AuthKit\User) {
            if ($this->requireActivation) {
                $this->sendActivationEmail($user, $request);
                $this->flash->add('info', 'Your account has been registered. Please check your email to activate it before logging in.');
            }

            $this->audit->register($user);
            $this->hooks->emit('register', $user);
        }

        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/login')->withStatus(302);
    }

    /**
     * Destroys the current session and redirects to /login.
     *
     * @param  Request  $request  Incoming request.
     * @param  Response $response PSR-7 response.
     * @return Response
     */
    public function logout(Request $request, Response $response): Response
    {
        $user = $this->auth->getUser();
        if ($user) {
            $this->audit->logout($user);
            $this->hooks->emit('logout', $user);
        }

        $this->auth->logout();
        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/login')->withStatus(302);
    }

    /**
     * Generates an activation token, stores it, and sends the activation email with tracking pixel.
     *
     * When the mailer is not configured, only the token is stored; no email is sent.
     *
     * @param \AuthKit\User $user    Newly registered user.
     * @param Request       $request Current HTTP request (used to build the activation URL).
     */
    private function sendActivationEmail(\AuthKit\User $user, Request $request): void
    {
        $token = bin2hex(random_bytes(32));

        $this->db->prepare(
            'INSERT INTO user_activations (user_id, token)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW()'
        )->execute([$user->get('id'), $token]);

        if ($this->mailer === null) {
            return;
        }

        $trackToken = bin2hex(random_bytes(16));

        $this->db->prepare('INSERT INTO mail_tracking (token, to_email, mail_type) VALUES (?, ?, ?)')
           ->execute([$trackToken, $user->getEmail(), 'activation']);

        $base = $this->baseUrl($request);

        $this->mailer->send(
            MailMessage::to($user->getEmail())
                ->subject('Activate your account')
                ->template('emails/activation.twig', [
                    'activation_link'    => $base . '/activate/' . $token,
                    'tracking_pixel_url' => $base . '/mail/track/' . $trackToken,
                ])
        );
    }

    /**
     * Derives the base URL (scheme + host + optional port) from the request.
     *
     * @param  Request $request
     * @return string
     */
    private function baseUrl(Request $request): string
    {
        $uri  = $request->getUri();
        $port = $uri->getPort();
        $base = $uri->getScheme() . '://' . $uri->getHost();
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $base .= ':' . $port;
        }
        return $base;
    }

}
