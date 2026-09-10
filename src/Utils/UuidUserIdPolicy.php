<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Utils;

use AuthKit\UserId\UserIdPolicyInterface;

/**
 * Assigns a UUID v4 string as the user ID on creation.
 *
 * Use this policy when the users table should use a CHAR(36) primary key
 * instead of an integer auto-increment column. Pass to PdoUserStorage:
 *
 *   new PdoUserStorage($pdo, new UuidUserIdPolicy())
 *
 * The schema is created automatically by Auth::createSchema() or
 * PdoUserStorage::createSchema() using the column definitions below.
 *
 * @package rafalmasiarek\DashboardKit\Utils
 */
final class UuidUserIdPolicy implements UserIdPolicyInterface
{
    /**
     * Generate a UUID v4 string to use as the new user's primary key.
     *
     * @return string UUID v4 in xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx format.
     */
    public function generate(): string
    {
        return Uuid::v4();
    }

    /**
     * @inheritDoc
     */
    public function idColumnDefinition(string $driver): string
    {
        return 'CHAR(36) NOT NULL';
    }

    /**
     * @inheritDoc
     */
    public function userIdForeignType(string $driver): string
    {
        return 'CHAR(36) NOT NULL';
    }
}
