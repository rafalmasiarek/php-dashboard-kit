<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use PDO;

/**
 * Reads the actual column list of an existing database table.
 *
 * Used by SchemaStateManager to detect columns missing from the live schema
 * so that ALTER TABLE ADD COLUMN statements can be generated for them.
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class SchemaInspector
{
    /**
     * Returns the column names that currently exist in the given table.
     *
     * Returns an empty array when the table does not exist yet.
     *
     * @param  string $table Table name.
     * @param  PDO    $pdo   Active database connection.
     * @return list<string>
     */
    public function columnNames(string $table, PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return $driver === 'sqlite'
            ? $this->sqliteColumnNames($table, $pdo)
            : $this->mysqlColumnNames($table, $pdo);
    }

    /**
     * Reads column names from INFORMATION_SCHEMA for MySQL/MariaDB.
     *
     * @param  string $table
     * @param  PDO    $pdo
     * @return list<string>
     */
    private function mysqlColumnNames(string $table, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Reads column names from PRAGMA table_info for SQLite.
     *
     * @param  string $table
     * @param  PDO    $pdo
     * @return list<string>
     */
    private function sqliteColumnNames(string $table, PDO $pdo): array
    {
        $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");

        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_column($rows, 'name'));
    }
}
