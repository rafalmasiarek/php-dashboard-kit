<?php

namespace rafalmasiarek\DashboardKit\Controllers;

use AuthKit\Auth;
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
 * Handles the forgot-password and password-reset flows.
 *
 * Routes (all public, no auth required):
 *   GET  /forgot-password          — render the "enter your email" form
 *   POST /forgot-password          — generate token and send reset email
 *   GET  /reset-password/{token}   — render the "choose new password" form
 *   POST /reset-password/{token}   — validate token and update password
 *
 * Hooks emitted:
 *   password_reset_requested (string $email)
 *   password_reset_completed (\AuthKit\User $user)
 *
 * @package rafalmasiarek\DashboardKit\Controllers
 */
class PasswordResetController
{
    /** @var int Token validity in seconds (1 hour). */
    private const TOKEN_TTL = 3600;

    /**
     * @param Twig                 $view               Twig rendering engine.
     * @param Auth                 $auth               AuthKit authentication service.
     * @param Flash                $flash              Flash message store.
     * @param PDO                  $db                 Database connection.
     * @param HookRegistry         $hooks              Event hook registry.
     * @param AuditLog             $audit              Audit logger.
     * @param array<string, mixed> $passwordStrength   Resolved password-strength config.
     * @param Mailer|null          $mailer             Optional mailer — reset link is logged when null.
     * @param string               $dashboardUrlPrefix Full URL prefix for dashboard redirects (e.g. '/api/admin').
     */
    public function __construct(
        private readonly Twig         $view,
        private readonly Auth         $auth,
        private readonly Flash        $flash,
        private readonly PDO          $db,
        private readonly HookRegistry $hooks,
        private readonly AuditLog     $audit,
        private readonly array        $passwordStrength,
        private readonly ?Mailer      $mailer,
        private readonly string       $dashboardUrlPrefix = '',
    ) {
    }

    /**
     * Renders the forgot-password form.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function requestForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'forgot-password.twig');
    }

    /**
     * Processes the forgot-password form.
     *
     * Always responds with a neutral flash to avoid revealing whether the email is registered.
     * When the email exists: generates a token, sends the reset email (or logs the link),
     * and emits the password_reset_requested hook.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function request(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId !== false) {
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL);

            $this->db->prepare(
                'INSERT INTO password_resets (user_id, token, expires_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()'
            )->execute([$userId, $token, $expiresAt]);

            $resetLink = $this->baseUrl($request) . '/reset-password/' . $token;

            if ($this->mailer !== null) {
                $this->mailer->send(
                    MailMessage::to($email)
                        ->subject('Reset your password')
                        ->template('emails/password-reset.twig', [
                            'user_email' => $email,
                            'reset_link' => $resetLink,
                            'expires_in' => '1 hour',
                        ])
                );
            }

            $this->audit->passwordResetRequested($email);
            $this->hooks->emit('password_reset_requested', $email);
        }

        $this->flash->add('info', 'If this email address is registered, you will receive a password reset link shortly.');
        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/login')->withStatus(302);
    }

    /**
     * Renders the reset-password form for a given token.
     *
     * Validates the token before rendering — redirects to /forgot-password on invalid/expired tokens.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (token).
     * @return Response
     */
    public function resetForm(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');

        if (!$this->tokenExists($token)) {
            $this->flash->add('danger', 'This password reset link is invalid or has expired.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/forgot-password')->withStatus(302);
        }

        return $this->view->render($response, 'reset-password.twig', ['token' => $token]);
    }

    /**
     * Processes the reset-password form.
     *
     * Validates token, password match and optional strength requirement, then updates
     * the password hash directly (bypassing current-password check), invalidates all
     * existing sessions, and emits the password_reset_completed hook.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (token).
     * @return Response
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $request->getParsedBody();

        $stmt = $this->db->prepare(
            'SELECT user_id FROM password_resets WHERE token = ? AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $userId = $stmt->fetchColumn();

        if ($userId === false) {
            $this->audit->passwordResetFailed();
            $this->flash->add('danger', 'This password reset link is invalid or has expired.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/forgot-password')->withStatus(302);
        }

        $password        = (string) ($body['password']         ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        if ($password !== $passwordConfirm) {
            $this->flash->add('danger', 'Passwords do not match.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/reset-password/' . \urlencode($token))->withStatus(302);
        }

        if ($this->passwordStrength['enabled'] ?? false) {
            $score    = PasswordStrength::score($password, $this->passwordStrength['rules'] ?? []);
            $minScore = (int) ($this->passwordStrength['min_score'] ?? 2);
            if ($score < $minScore) {
                $this->flash->add('danger', 'Your password is too weak. Please choose a stronger one.');
                return $response->withHeader('Location', $this->dashboardUrlPrefix . '/reset-password/' . \urlencode($token))->withStatus(302);
            }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        $this->db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);

        $this->auth->forceLogoutUser((int) $userId, 'password reset');

        $userStmt = $this->db->prepare('SELECT id, email, role FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $row = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $user = new \AuthKit\User($row);
            $this->audit->passwordResetCompleted($user);
            $this->hooks->emit('password_reset_completed', $user);
        }

        $this->flash->add('success', 'Your password has been reset. You can now log in.');
        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/login')->withStatus(302);
    }

    /**
     * Checks whether a token exists in password_resets and has not yet expired.
     *
     * @param  string $token
     * @return bool
     */
    private function tokenExists(string $token): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM password_resets WHERE token = ? AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        return $stmt->fetchColumn() !== false;
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
