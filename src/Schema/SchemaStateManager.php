<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use PDO;

/**
 * Manages module schema lifecycle: creation, column diffing, and state tracking.
 *
 * On every boot, SchemaStateManager compares a hash of each table's declared
 * schema against the hash stored in `_schema_state`. Tables whose hash has not
 * changed are skipped entirely — no INFORMATION_SCHEMA queries, no ALTER TABLE.
 * Only tables with a changed or missing hash go through the full sync cycle:
 *
 *   1. CREATE TABLE IF NOT EXISTS  (idempotent, driver-specific DDL via ModuleSchemaBuilder)
 *   2. Introspect actual columns   (SchemaInspector — only on hash mismatch)
 *   3. ALTER TABLE ADD COLUMN      (only for columns declared but absent in DB)
 *   4. Upsert _schema_state        (new hash + column count + timestamp)
 *
 * Columns are never dropped or modified — only added.
 * New NOT NULL columns added to tables with existing rows must declare a DEFAULT value.
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class SchemaStateManager
{
    /**
     * @param PDO                $pdo         Active database connection.
     * @param ModuleSchemaBuilder $builder     Builds DDL statements from declarative arrays.
     * @param SchemaInspector    $inspector   Reads actual column lists from the live schema.
     * @param string             $tablePrefix Optional prefix applied to every module table name.
     *                                        System tables (_schema_state, _table_version, _query_cache)
     *                                        are never prefixed.
     */
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly ModuleSchemaBuilder $builder,
        private readonly SchemaInspector     $inspector,
        private readonly string              $tablePrefix = '',
    ) {
    }

    /**
     * Syncs all module schemas against the live database.
     *
     * @param array<string, array<string, mixed>> $modules Map of module slug → module definition
     *                                                      (only modules with an array 'schema' key are processed).
     */
    public function sync(array $modules): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->ensureSystemTables($driver);

        $tables = $this->collectTables($modules);

        if ($tables === []) {
            return;
        }

        $stored = $this->loadStoredHashes(array_keys($tables));

        foreach ($tables as $tableName => ['definition' => $definition, 'module' => $module]) {
            $hash = md5(serialize($definition));

            if (($stored[$tableName] ?? null) === $hash) {
                continue;
            }

            foreach ($this->builder->statementsFor($tableName, $definition, $driver) as $sql) {
                $this->pdo->exec($sql);
            }

            $actual   = $this->inspector->columnNames($tableName, $this->pdo);
            $declared = $this->builder->declaredColumns($definition, $driver);

            foreach ($declared as $colName => $colDdl) {
                if (!in_array($colName, $actual, true)) {
                    $this->pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN {$colDdl}");
                }
            }

            $this->upsertState($tableName, $module, $hash, count($declared), $driver);
        }
    }

    /**
     * Flattens all module schema definitions into a single table map.
     *
     * @param  array<string, array<string, mixed>> $modules
     * @return array<string, array{definition: array<string, mixed>, module: string}>
     */
    private function collectTables(array $modules): array
    {
        $tables = [];

        foreach ($modules as $slug => $module) {
            foreach ((array) ($module['schema'] ?? []) as $tableName => $definition) {
                $tables[$this->tablePrefix . (string) $tableName] = [
                    'definition' => (array) $definition,
                    'module'     => (string) $slug,
                ];
            }
        }

        return $tables;
    }

    /**
     * Loads stored hashes for all given table names in a single query.
     *
     * @param  list<string> $tableNames
     * @return array<string, string> Map of table name → stored hash.
     */
    private function loadStoredHashes(array $tableNames): array
    {
        $placeholders = implode(',', array_fill(0, count($tableNames), '?'));
        $stmt         = $this->pdo->prepare(
            "SELECT table_name, schema_hash FROM `_schema_state` WHERE table_name IN ({$placeholders})"
        );
        $stmt->execute($tableNames);

        return (array) $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Creates all system tables required by the dashboard infrastructure.
     *
     * Tables created (all prefixed with underscore to avoid collisions):
     *   _schema_state  — hash + column count per module table; drives the schema sync fast path.
     *   _table_version — monotonic write counter per table; used by TableVersionTracker for
     *                    version-keyed cache invalidation.
     *   _query_cache   — serialized query results with TTL; used by QueryCacheStore.
     *
     * @param string $driver PDO driver name ('mysql' or 'sqlite').
     */
    private function ensureSystemTables(string $driver): void
    {
        if ($driver === 'mysql') {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_schema_state` (
                    `table_name`   VARCHAR(64) NOT NULL,
                    `module`       VARCHAR(64) NOT NULL,
                    `schema_hash`  CHAR(32)    NOT NULL,
                    `column_count` SMALLINT    NOT NULL,
                    `synced_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                               ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`table_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_table_version` (
                    `table_name` VARCHAR(64)      NOT NULL,
                    `version`    BIGINT UNSIGNED   NOT NULL DEFAULT 0,
                    `updated_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                   ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`table_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_query_cache` (
                    `cache_key`  VARCHAR(128) NOT NULL,
                    `payload`    MEDIUMTEXT   NOT NULL,
                    `expires_at` DATETIME     NOT NULL,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`cache_key`),
                    INDEX `idx_expires_at` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_schema_state` (
                    `table_name`   TEXT    NOT NULL,
                    `module`       TEXT    NOT NULL,
                    `schema_hash`  TEXT    NOT NULL,
                    `column_count` INTEGER NOT NULL,
                    `synced_at`    TEXT    NOT NULL DEFAULT (datetime('now')),
                    PRIMARY KEY (`table_name`)
                )"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_table_version` (
                    `table_name` TEXT    NOT NULL,
                    `version`    INTEGER NOT NULL DEFAULT 0,
                    `updated_at` TEXT    NOT NULL DEFAULT (datetime('now')),
                    PRIMARY KEY (`table_name`)
                )"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_query_cache` (
                    `cache_key`  TEXT NOT NULL,
                    `payload`    TEXT NOT NULL,
                    `expires_at` TEXT NOT NULL,
                    `created_at` TEXT NOT NULL DEFAULT (datetime('now')),
                    PRIMARY KEY (`cache_key`)
                )"
            );
        }
    }

    /**
     * Inserts or updates the state record for one table.
     *
     * @param string $table  Table name.
     * @param string $module Module slug that owns the table.
     * @param string $hash   md5 hash of the serialized schema definition.
     * @param int    $count  Number of declared columns after auto-injection.
     * @param string $driver PDO driver name.
     */
    private function upsertState(string $table, string $module, string $hash, int $count, string $driver): void
    {
        if ($driver === 'mysql') {
            $this->pdo->prepare(
                'INSERT INTO `_schema_state` (table_name, module, schema_hash, column_count)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE schema_hash = ?, column_count = ?'
            )->execute([$table, $module, $hash, $count, $hash, $count]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO `_schema_state` (table_name, module, schema_hash, column_count, synced_at)
                 VALUES (?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(table_name) DO UPDATE SET
                     schema_hash  = excluded.schema_hash,
                     column_count = excluded.column_count,
                     synced_at    = datetime('now')"
            )->execute([$table, $module, $hash, $count]);
        }
    }
}
