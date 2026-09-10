<?php

namespace rafalmasiarek\DashboardKit\Log;

use AuthKit\User;
use Psr\Log\LoggerInterface;

/**
 * Writes structured audit entries for dashboard user and admin actions.
 *
 * Each method logs at INFO level with a consistent context shape:
 *   user_id, email — always present
 *   admin_id, admin_email — present for admin-initiated actions
 *   extra payload (old_email, fields, old_role, new_role) — action-specific
 *
 * The client IP is injected automatically into every log record by
 * RequestHeadersProcessor (req.ip) and does not need to be passed explicitly.
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
class AuditLog
{
    /**
     * @param LoggerInterface $logger PSR-3 logger receiving audit entries.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Logs a successful user registration.
     *
     * @param User $user Newly registered user.
     */
    public function register(User $user): void
    {
        $this->logger->info('dashboard.register', $this->ctx($user));
    }

    /**
     * Logs a successful login.
     *
     * @param User $user Authenticated user.
     */
    public function login(User $user): void
    {
        $this->logger->info('dashboard.login', $this->ctx($user));
    }

    /**
     * Logs a logout.
     *
     * @param User $user User who logged out.
     */
    public function logout(User $user): void
    {
        $this->logger->info('dashboard.logout', $this->ctx($user));
    }

    /**
     * Logs a password change initiated by the user themselves.
     *
     * @param User $user User who changed their password.
     */
    public function passwordChanged(User $user): void
    {
        $this->logger->info('dashboard.password_changed', $this->ctx($user));
    }

    /**
     * Logs an email address change.
     *
     * @param User   $user     User whose email changed.
     * @param string $oldEmail Previous email address.
     */
    public function emailChanged(User $user, string $oldEmail): void
    {
        $this->logger->info('dashboard.email_changed', $this->ctx($user) + [
            'old_email' => $oldEmail,
        ]);
    }

    /**
     * Logs a profile fields update.
     *
     * @param User                 $user   User whose profile was updated.
     * @param array<string, mixed> $fields Map of field names that were updated.
     */
    public function profileUpdated(User $user, array $fields): void
    {
        $this->logger->info('dashboard.profile_updated', $this->ctx($user) + [
            'fields' => array_keys($fields),
        ]);
    }

    /**
     * Logs an admin suspending a user account.
     *
     * @param User $target Suspended user.
     * @param User $admin  Admin who performed the action.
     */
    public function userSuspended(User $target, User $admin): void
    {
        $this->logger->info('dashboard.user_suspended', $this->adminCtx($target, $admin));
    }

    /**
     * Logs an admin unsuspending a user account.
     *
     * @param User $target Unsuspended user.
     * @param User $admin  Admin who performed the action.
     */
    public function userUnsuspended(User $target, User $admin): void
    {
        $this->logger->info('dashboard.user_unsuspended', $this->adminCtx($target, $admin));
    }

    /**
     * Logs an admin changing a user's role.
     *
     * @param User   $target  User whose role changed.
     * @param string $oldRole Previous role value.
     * @param string $newRole New role value.
     * @param User   $admin   Admin who performed the action.
     */
    public function roleChanged(User $target, string $oldRole, string $newRole, User $admin): void
    {
        $this->logger->info('dashboard.role_changed', $this->adminCtx($target, $admin) + [
            'old_role' => $oldRole,
            'new_role' => $newRole,
        ]);
    }

    /**
     * Logs an admin editing a user's account fields.
     *
     * @param User               $target        User whose account was updated.
     * @param array<int, string> $changedFields Names of fields that were modified.
     * @param User               $admin         Admin who performed the action.
     */
    public function userUpdated(User $target, array $changedFields, User $admin): void
    {
        $this->logger->info('dashboard.user_updated', $this->adminCtx($target, $admin) + [
            'changed' => $changedFields,
        ]);
    }

    /**
     * Logs an admin permanently deleting a user account.
     *
     * @param User $target User who was deleted.
     * @param User $admin  Admin who performed the action.
     */
    public function userDeleted(User $target, User $admin): void
    {
        $this->logger->info('dashboard.user_deleted', $this->adminCtx($target, $admin));
    }

    /**
     * Logs an admin manually creating a new user account.
     *
     * @param User $target Created user.
     * @param User $admin  Admin who performed the action.
     */
    public function userCreated(User $target, User $admin): void
    {
        $this->logger->info('dashboard.user_created', $this->adminCtx($target, $admin));
    }

    /**
     * Logs an account activation attempt with its outcome.
     *
     * @param bool        $success Whether the token was valid and the account was activated.
     * @param int|null    $userId  Resolved user ID (present on success only).
     * @param string|null $email   Resolved user email (present on success only).
     */
    public function activationAttempt(bool $success, ?int $userId = null, ?string $email = null): void
    {
        $context = ['success' => $success];

        if ($userId !== null) {
            $context['user_id'] = $userId;
        }
        if ($email !== null) {
            $context['email'] = $email;
        }

        $success
            ? $this->logger->info('activation.attempt', $context)
            : $this->logger->error('activation.attempt', $context);
    }

    /**
     * Logs a mail delivery attempt with its outcome.
     *
     * Logs at INFO on success, ERROR on failure — same event name in both cases,
     * so a single grep shows the full picture: sent + failed side by side.
     *
     * @param bool        $success Whether the driver accepted the message without throwing.
     * @param string      $to      Recipient email address.
     * @param string      $subject Email subject line.
     * @param string|null $error   Driver error message (present on failure only).
     */
    public function mailAttempt(bool $success, string $to, string $subject, ?string $error = null): void
    {
        $context = ['success' => $success, 'to' => $to, 'subject' => $subject];
        if ($error !== null) {
            $context['error'] = $error;
        }
        $success
            ? $this->logger->info('dashboard.mail_attempt', $context)
            : $this->logger->error('dashboard.mail_attempt', $context);
    }

    /**
     * Logs a tracking-pixel open event — the recipient opened the email.
     *
     * @param string $to       Recipient email address.
     * @param string $mailType Application-defined mail type (e.g. 'activation', 'password-reset').
     */
    public function mailOpen(string $to, string $mailType): void
    {
        $this->logger->info('dashboard.mail_open', ['to' => $to, 'mail_type' => $mailType]);
    }

    /**
     * Logs a password reset request (token generated and email dispatched).
     *
     * @param string $email Email address for which the reset was requested.
     */
    public function passwordResetRequested(string $email): void
    {
        $this->logger->info('dashboard.password_reset_requested', ['email' => $email]);
    }

    /**
     * Logs a successfully completed password reset.
     *
     * @param User $user User who completed the reset.
     */
    public function passwordResetCompleted(User $user): void
    {
        $this->logger->info('dashboard.password_reset_completed', $this->ctx($user));
    }

    /**
     * Logs a failed login attempt.
     *
     * @param string $email  Email address used in the attempt.
     * @param string $reason Error message returned by AuthKit.
     */
    public function loginFailed(string $email, string $reason): void
    {
        $this->logger->warning('dashboard.login', ['success' => false, 'email' => $email, 'reason' => $reason]);
    }

    /**
     * Logs a failed registration attempt.
     *
     * @param string $email  Email address used in the attempt.
     * @param string $reason Error message returned by AuthKit.
     */
    public function registerFailed(string $email, string $reason): void
    {
        $this->logger->warning('dashboard.register', ['success' => false, 'email' => $email, 'reason' => $reason]);
    }

    /**
     * Logs a failed password change attempt.
     *
     * @param User   $user   User who attempted the change.
     * @param string $reason Description of why the change was rejected.
     */
    public function passwordChangeFailed(User $user, string $reason): void
    {
        $this->logger->warning('dashboard.password_changed', $this->ctx($user) + ['success' => false, 'reason' => $reason]);
    }

    /**
     * Logs a failed password reset completion (invalid or expired token).
     *
     * @param string|null $email Email address associated with the reset attempt, if known.
     */
    public function passwordResetFailed(?string $email = null): void
    {
        $this->logger->warning('dashboard.password_reset_completed', array_filter(['success' => false, 'email' => $email], fn($v) => $v !== null));
    }

    /**
     * Builds the base context array for user-initiated actions.
     *
     * @param  User $user
     * @return array<string, mixed>
     */
    private function ctx(User $user): array
    {
        return [
            'user_id' => $user->get('id'),
            'email'   => $user->getEmail(),
        ];
    }

    /**
     * Builds the context array for admin-initiated actions targeting another user.
     *
     * @param  User $target
     * @param  User $admin
     * @return array<string, mixed>
     */
    private function adminCtx(User $target, User $admin): array
    {
        return [
            'user_id'     => $target->get('id'),
            'email'       => $target->getEmail(),
            'admin_id'    => $admin->get('id'),
            'admin_email' => $admin->getEmail(),
        ];
    }
}
