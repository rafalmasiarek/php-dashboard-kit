<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use AuthKit\Extension\SchemaProviderInterface;

/**
 * Declares the password_resets table required by the password-reset flow.
 *
 * Pass to Auth::createSchema() only when password_reset is enabled:
 *
 *   $auth->createSchema(new DashboardUserColumnsProvider(), new PasswordResetSchemaProvider(), ...);
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class PasswordResetSchemaProvider implements SchemaProviderInterface
{
    /**
     * @param  string        $driver PDO driver name.
     * @return list<string>
     */
    public function additionalSchema(string $driver): array
    {
        if ($driver === 'sqlite') {
            return [
                'CREATE TABLE IF NOT EXISTS password_resets (
                    id         INTEGER  NOT NULL PRIMARY KEY AUTOINCREMENT,
                    user_id    TEXT     NOT NULL UNIQUE,
                    token      TEXT     NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS password_resets (
                id         INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id    CHAR(36) NOT NULL UNIQUE,
                token      CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT NOW()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}
