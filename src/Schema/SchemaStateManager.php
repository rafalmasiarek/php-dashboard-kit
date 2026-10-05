<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use PDO;
use Psr\Clock\ClockInterface;
use rafalmasiarek\DashboardKit\Util\SystemClock;

/**
 * Manages module schema lifecycle: creation, column diffing, and state tracking.
 *
 * On every boot, SchemaStateManager compares a hash of each table's declared
 * schema (folded together with the injected ModuleSchemaBuilder's own default
 * flags, so a config-level default change invalidates every table that relies
 * on it) against the hash stored in `_schema_state`. Tables whose hash has not
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
     * @param PDO                 $pdo         Active database connection.
     * @param ModuleSchemaBuilder $builder     Builds DDL statements from declarative arrays.
     * @param SchemaInspector     $inspector   Reads actual column lists from the live schema.
     * @param string              $tablePrefix Optional prefix applied to every module table name.
     *                                         System tables (_schema_state, _table_version, _query_cache)
     *                                         are never prefixed.
     * @param ClockInterface|null $clock       Clock for the SQLite ALTER-backfill literal below.
     *                                         Defaults to SystemClock.
     */
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly ModuleSchemaBuilder $builder,
        private readonly SchemaInspector     $inspector,
        private readonly string              $tablePrefix = '',
        private readonly ?ClockInterface      $clock = null,
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

        $buildDefaultsFingerprint = ((int) $this->builder->defaultTimestamps) . ((int) $this->builder->defaultSoftDeletes);

        foreach ($tables as $tableName => ['definition' => $definition, 'module' => $module]) {
            // Builder defaults folded in — otherwise a config-only default change is
            // invisible to serialize($definition) and the ALTER cycle below never fires.
            $hash = md5(serialize($definition) . '|' . $buildDefaultsFingerprint);

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
                    $this->pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN " . $this->forAlter($colDdl, $driver));
                }
            }

            $this->upsertState($tableName, $module, $hash, count($declared), $driver);
        }
    }

    /**
     * Adapts a column DDL fragment for use in ALTER TABLE ADD COLUMN.
     *
     * SQLite rejects a non-constant default (CURRENT_TIMESTAMP included) on
     * ADD COLUMN, even though the exact same default is accepted on CREATE
     * TABLE — "Cannot add a column with non-constant default". Substituted
     * with a literal snapshot of now, so the backfill succeeds; the column's
     * default for any future INSERT on SQLite is this same frozen literal, a
     * known, narrower version of the "no ON UPDATE equivalent on SQLite"
     * limitation already documented on ModuleSchemaBuilder. MySQL accepts
     * CURRENT_TIMESTAMP on ADD COLUMN natively and is left untouched.
     *
     * @param  string $colDdl Column DDL fragment from declaredColumns().
     * @param  string $driver PDO driver name.
     * @return string
     */
    private function forAlter(string $colDdl, string $driver): string
    {
        if ($driver !== 'sqlite') {
            return $colDdl;
        }

        $now = ($this->clock ?? new SystemClock())->now()->format('Y-m-d H:i:s');

        return str_replace('DEFAULT CURRENT_TIMESTAMP', "DEFAULT '{$now}'", $colDdl);
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
