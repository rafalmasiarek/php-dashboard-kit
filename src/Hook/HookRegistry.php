<?php

namespace rafalmasiarek\DashboardKit\Hook;

/**
 * Lightweight event hook registry.
 *
 * Each event holds exactly one listener — calling on() replaces any previous
 * registration for that event. This is intentional: it allows application code
 * to override the default flash-message hooks registered by the dashboard without
 * ending up with two flashes firing simultaneously.
 *
 * Supported events and their argument signatures:
 *   register         (User $user)
 *   login            (User $user)
 *   logout           (User $user)
 *   password_changed (User $user)
 *   email_changed    (User $user, string $oldEmail)
 *   profile_updated  (User $user, array $updatedFields)
 *   user_suspended   (User $target, User $admin)
 *   user_unsuspended (User $target, User $admin)
 *   role_changed     (User $target, string $oldRole, string $newRole, User $admin)
 *   user_updated     (User $target, string[] $changedFields, User $admin)
 *   user_deleted     (User $target, User $admin)
 *
 * @package rafalmasiarek\DashboardKit\Hook
 */
class HookRegistry
{
    /** @var array<string, callable> */
    private array $listeners = [];

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
     * Calls the listener registered for the given event, if any.
     *
     * Exceptions thrown by the listener propagate to the caller.
     *
     * @param string $event Event name.
     * @param mixed  ...$args Arguments forwarded to the listener.
     */
    public function emit(string $event, mixed ...$args): void
    {
        if (isset($this->listeners[$event])) {
            ($this->listeners[$event])(...$args);
        }
    }
}
