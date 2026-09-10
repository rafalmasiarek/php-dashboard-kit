<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Extension;

use AuthKit\Extension\LoginExtensionInterface;
use AuthKit\Extension\SchemaProviderInterface;
use AuthKit\LoginContext;
use AuthKit\LoginDecision;

/**
 * Blocks login when the user's account has not yet been activated via email.
 *
 * Register only when require_activation is enabled:
 *
 *   $auth->addLoginExtension(new ActiveUserExtension());
 *
 * Implements SchemaProviderInterface so that Auth::createSchema() automatically
 * creates the user_activations table required by the activation token flow.
 *
 * @package rafalmasiarek\DashboardKit\Extension
 */
final class ActiveUserExtension implements LoginExtensionInterface, SchemaProviderInterface
{
    /**
     * Deny login when the active flag is falsy on the user record.
     *
     * @param  LoginContext  $context
     * @return LoginDecision
     */
    public function decide(LoginContext $context): LoginDecision
    {
        if (!$context->user->get('active')) {
            return LoginDecision::deny(
                'Your account is not yet activated. Please check your email for the activation link.'
            );
        }

        return LoginDecision::allow();
    }

    /**
     * Declares the user_activations table required by the activation token flow.
     *
     * @param  string        $driver PDO driver name.
     * @return list<string>
     */
    public function additionalSchema(string $driver): array
    {
        if ($driver === 'sqlite') {
            return [
                'CREATE TABLE IF NOT EXISTS user_activations (
                    user_id    TEXT     NOT NULL PRIMARY KEY,
                    token      TEXT     NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS user_activations (
                user_id    CHAR(36)    NOT NULL PRIMARY KEY,
                token      VARCHAR(64) NOT NULL,
                created_at DATETIME    NOT NULL DEFAULT NOW()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}
