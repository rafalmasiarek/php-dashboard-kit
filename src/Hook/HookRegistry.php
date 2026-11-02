<?php

namespace rafalmasiarek\DashboardKit\Hook;

use Psr\Log\LoggerInterface;

/**
 * Lightweight event hook registry.
 *
 * Each event holds exactly one on()-registered listener — calling on() replaces
 * any previous registration for that event. This is intentional: it allows
 * application code to override the default flash-message hooks registered by
 * the dashboard without ending up with two flashes firing simultaneously.
 *
 * addListener() is a separate, additive mechanism for events meant to host
 * multiple independent plugins (e.g. mail_sending): every registered listener
 * runs, in registration order, and a listener that throws is caught and logged
 * — it never blocks the other listeners or whatever called emit().
 *
 * Supported events and their argument signatures:
 *   register                  (User $user)
 *   register_failed           (string $email, string $message)
 *   login                     (User $user)
 *   login_failed              (string $email)
 *   logout                    (User $user)
 *   password_reset_requested  (string $email)
 *   password_reset_completed  (User $user)
 *   password_changed          (User $user)
 *   email_changed             (User $user, string $oldEmail)
 *   profile_updated           (User $user, array $updatedFields)
 *   user_created              (User $target, User $admin)
 *   user_suspended            (User $target, User $admin)
 *   user_unsuspended          (User $target, User $admin)
 *   user_updated              (User $target, string[] $changedFields, User $admin)
 *   user_deleted              (User $target, User $admin)
 *   mail_sending               (MailMessage $message) — addListener() only, before delivery
 *   mail_sent                 (MailMessage $message)
 *   mail_failed               (MailMessage $message, \Throwable $e)
 *
 * @package rafalmasiarek\DashboardKit\Hook
 */
class HookRegistry
{
    /** @var array<string, callable> */
    private array $listeners = [];

    /** @var array<string, list<callable>> */
    private array $pluginListeners = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Registers a listener for the given event, replacing any previous one.
     *
     * @param  string   $event    Event name (see class docblock for supported events).
     * @param  callable $listener Callback invoked with event-specific arguments.
     * @return self
     */
    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event] = $listener;
        return $this;
    }

    /**
     * Registers an additional, isolated listener for the given event.
     *
     * Unlike on(), multiple callers can each addListener() for the same event
     * without overwriting one another. A listener that throws is caught and
     * logged by emit() — it never blocks the other listeners for this event,
     * nor whatever called emit() in the first place. Intended for plugin-style
     * integrations (e.g. mail_sending) where an addon must never be able to
     * break the core flow it's observing.
     *
     * @param  string   $event    Event name (see class docblock for supported events).
     * @param  callable $listener Callback invoked with event-specific arguments.
     * @return self
     */
    public function addListener(string $event, callable $listener): self
    {
        $this->pluginListeners[$event][] = $listener;
        return $this;
    }

    /**
     * Calls the listener registered for the given event via on(), if any, then
     * every listener registered via addListener(), in registration order.
     *
     * Exceptions thrown by the on() listener propagate to the caller, as
     * before. Exceptions thrown by an addListener() listener are caught and
     * logged — they never propagate.
     *
     * @param string $event Event name.
     * @param mixed  ...$args Arguments forwarded to the listener.
     */
    public function emit(string $event, mixed ...$args): void
    {
        if (isset($this->listeners[$event])) {
            ($this->listeners[$event])(...$args);
        }

        foreach ($this->pluginListeners[$event] ?? [] as $listener) {
            try {
                $listener(...$args);
            } catch (\Throwable $e) {
                if ($this->logger !== null) {
                    $this->logger->error('hook.listener_failed', ['event' => $event, 'exception' => $e]);
                } else {
                    error_log(sprintf('[hook:%s] listener failed: %s', $event, $e->getMessage()));
                }
            }
        }
    }
}
