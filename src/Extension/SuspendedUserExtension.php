<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Extension;

use AuthKit\Extension\LoginExtensionInterface;
use AuthKit\Extension\SchemaProviderInterface;
use AuthKit\LoginContext;
use AuthKit\LoginDecision;

/**
 * Blocks login when the user's account has been suspended by an admin.
 *
 * Register unconditionally via Auth::addLoginExtension():
 *
 *   $auth->addLoginExtension(new SuspendedUserExtension());
 *
 * Implements SchemaProviderInterface so that Auth::createSchema() automatically
 * adds the suspended_at column to the users table.
 *
 * @package rafalmasiarek\DashboardKit\Extension
 */
final class SuspendedUserExtension implements LoginExtensionInterface, SchemaProviderInterface
{
    /**
     * Deny login when suspended_at is set on the user record.
     *
     * @param  LoginContext  $context
     * @return LoginDecision
     */
    public function decide(LoginContext $context): LoginDecision
    {
        if ($context->user->get('suspended_at')) {
            return LoginDecision::deny('Your account has been suspended.');
        }

        return LoginDecision::allow();
    }

    /**
     * Declares the suspended_at column required by this extension.
     *
     * @param  string        $driver PDO driver name.
     * @return list<string>
     */
    public function additionalSchema(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [
                'ALTER TABLE users ADD COLUMN suspended_at TEXT NULL DEFAULT NULL',
            ],
            default => [
                'ALTER TABLE users ADD COLUMN suspended_at DATETIME NULL DEFAULT NULL',
            ],
        };
    }
}
