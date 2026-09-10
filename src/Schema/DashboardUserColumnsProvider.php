<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use AuthKit\Extension\SchemaProviderInterface;

/**
 * Declares the extra columns on the users table that dashboard-kit always requires.
 *
 * Pass unconditionally to Auth::createSchema() so that role and active exist
 * regardless of which login extensions are registered:
 *
 *   $auth->createSchema(new DashboardUserColumnsProvider(), ...);
 *
 * Column semantics:
 *   role       — 'user' or 'admin'; governs access to the /admin panel.
 *   active     — 1 = account usable, 0 = pending activation or manually disabled.
 *                Defaults to 0 (safe minimum). Code that creates immediately-usable
 *                accounts must set active = 1 explicitly (admin panel, registration
 *                without require_activation).
 *   updated_at — Timestamp of the last mutation to any column in this row.
 *                MySQL: maintained automatically via ON UPDATE CURRENT_TIMESTAMP.
 *                SQLite: must be updated explicitly by application code on writes.
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class DashboardUserColumnsProvider implements SchemaProviderInterface
{
    /**
     * @param  string        $driver PDO driver name.
     * @return list<string>
     */
    public function additionalSchema(string $driver): array
    {
        if ($driver === 'sqlite') {
            return [
                "ALTER TABLE users ADD COLUMN role       TEXT    NOT NULL DEFAULT 'user'",
                'ALTER TABLE users ADD COLUMN active     INTEGER NOT NULL DEFAULT 0',
                "ALTER TABLE users ADD COLUMN updated_at TEXT    NOT NULL DEFAULT (datetime('now'))",
            ];
        }

        return [
            "ALTER TABLE users ADD COLUMN role       VARCHAR(50) NOT NULL DEFAULT 'user'",
            'ALTER TABLE users ADD COLUMN active     TINYINT     NOT NULL DEFAULT 0',
            'ALTER TABLE users ADD COLUMN updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];
    }
}
