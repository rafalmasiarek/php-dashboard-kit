<?php

namespace rafalmasiarek\DashboardKit\Controllers;

use AuthKit\Auth;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use rafalmasiarek\DashboardKit\Flash;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use Slim\Views\Twig;

/**
 * Handles the current user's account settings: password, email changes, and custom profile fields.
 *
 * Routes are registered internally by Dashboard and protected by AuthMiddleware.
 *
 * @package rafalmasiarek\DashboardKit\Controllers
 */
class SettingsController
{
    /**
     * @param Twig         $view                Twig rendering engine.
     * @param Auth         $auth                AuthKit authentication service.
     * @param Flash        $flash               Flash message store.
     * @param PDO          $db                  Database connection.
     * @param array<string, array{label: string, type: string, max: int, required: bool}> $userFields Extra profile fields.
     * @param HookRegistry $hooks               Event hook registry.
     * @param AuditLog     $audit               Audit logger.
     * @param string       $dashboardUrlPrefix  Full URL prefix for dashboard routes (base_path + dashboard.prefix).
     */
    public function __construct(
        private readonly Twig         $view,
        private readonly Auth         $auth,
        private readonly Flash        $flash,
        private readonly PDO          $db,
        private readonly array        $userFields,
        private readonly HookRegistry $hooks,
        private readonly AuditLog     $audit,
        private readonly string       $dashboardUrlPrefix = '',
    ) {
    }

    /**
     * Renders the settings page.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function index(Request $request, Response $response): Response
    {
        $profileValues = [];
        if (!empty($this->userFields)) {
            $user = $this->auth->getUser();
            foreach ($this->userFields as $name => $field) {
                $profileValues[$name] = (string) ($user->get($name) ?? '');
            }
        }

        return $this->view->render($response, 'settings/index.twig', [
            'title'          => 'Settings',
            'user'           => $this->auth->getUser(),
            'profile_values' => $profileValues,
        ]);
    }

    /**
     * Processes a password change request.
     *
     * Validates current password before applying the new one.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $body    = (array) $request->getParsedBody();
        $current = (string) ($body['current_password'] ?? '');
        $new     = (string) ($body['new_password'] ?? '');
        $confirm = (string) ($body['confirm_password'] ?? '');

        $user = $this->auth->getUser();

        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user->get('id')]);
        $hash = (string) ($stmt->fetchColumn() ?: '');

        if (!password_verify($current, $hash)) {
            $this->audit->passwordChangeFailed($user, 'Current password is incorrect.');
            $this->flash->add('danger', 'Current password is incorrect.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        if ($new === '') {
            $this->flash->add('danger', 'New password cannot be empty.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        if ($new !== $confirm) {
            $this->flash->add('danger', 'New passwords do not match.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        $this->auth->updateUser($user, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);

        $this->audit->passwordChanged($user);
        $this->hooks->emit('password_changed', $user);

        $this->flash->add('success', 'Password changed successfully.');
        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
    }

    /**
     * Processes an email address change request.
     *
     * Rejects the new address if it is already taken by another account.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function changeEmail(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $newEmail = strtolower(trim((string) ($body['email'] ?? '')));
        $user     = $this->auth->getUser();

        if ($newEmail === '') {
            $this->flash->add('danger', 'Email address cannot be empty.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $this->flash->add('danger', 'Invalid email address.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        $oldEmail = (string) $user->getEmail();

        if ($newEmail === strtolower(trim($oldEmail))) {
            $this->flash->add('info', 'That is already your email address.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$newEmail, (int) $user->get('id')]);
        if ($stmt->fetchColumn()) {
            $this->flash->add('danger', 'That email address is already in use.');
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        $this->auth->updateUser($user, ['email' => $newEmail]);

        $this->audit->emailChanged($user, $oldEmail);
        $this->hooks->emit('email_changed', $user, $oldEmail);

        $this->flash->add('success', 'Email address updated.');
        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
    }

    /**
     * Processes a profile fields update (all user_fields at once).
     *
     * Validates required fields before saving. Empty optional values are stored as NULL.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function updateProfile(Request $request, Response $response): Response
    {
        if (empty($this->userFields)) {
            return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
        }

        $body    = (array) $request->getParsedBody();
        $user    = $this->auth->getUser();
        $updates = [];

        foreach ($this->userFields as $name => $field) {
            $value = trim((string) ($body[$name] ?? ''));
            if ($field['required'] && $value === '') {
                $this->flash->add('danger', $field['label'] . ' is required.');
                return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
            }
            $updates[$name] = $value !== '' ? $value : null;
        }

        $this->auth->updateUser($user, $updates);

        $this->audit->profileUpdated($user, $updates);
        $this->hooks->emit('profile_updated', $user, $updates);

        $this->flash->add('success', 'Profile updated.');
        return $response->withHeader('Location', $this->dashboardUrlPrefix . '/settings')->withStatus(302);
    }

}
