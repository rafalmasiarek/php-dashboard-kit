<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Hook;

use AuthKit\Hook\AbstractHook;
use rafalmasiarek\DashboardKit\Flash;

/**
 * Bridges AuthKit's low-level lifecycle hooks to dashboard-kit's flash messages.
 *
 * Only overrides hooks with no HookRegistry equivalent. onLogoutExpired fires
 * from deep inside Auth::getUser() when a session token existed but is no
 * longer valid (expired or revoked) — a case no controller action ever sees,
 * so HookRegistry (which controllers emit into) can never cover it.
 *
 * @package rafalmasiarek\DashboardKit\Hook
 */
final class FlashAuthHook extends AbstractHook
{
    /**
     * @param Flash $flash
     */
    public function __construct(private readonly Flash $flash)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function onLogoutExpired(): void
    {
        $this->flash->add('warning', 'You have been logged out due to inactivity.');
    }
}
