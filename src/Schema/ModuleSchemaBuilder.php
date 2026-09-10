<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use PDO;

/**
 * Builds and executes CREATE TABLE IF NOT EXISTS statements from a declarative array.
 *
 * Supports MySQL and SQLite. Schema arrays are defined in module.php under the
 * 'schema' key and processed by SchemaStateManager on every boot.
 *
 * System columns injected automatically (unless already defined by the user):
 *   id         — CHAR(36) NOT NULL, PRIMARY KEY (UUID v4, application-generated)
 *   created_at — DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * Optional system column, enabled per-table with 'timestamps' => true:
 *   updated_at — DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 * Table definition format:
 *
 *   'schema' => [
 *       'my_table' => [
 *           'timestamps'   => true,                    // adds updated_at (optional)
 *           'columns' => [
 *               'user_id' => ['type' => 'id_ref',      'null' => false],
 *               'label'   => ['type' => 'varchar(255)', 'null' => false],
 *               'note'    => ['type' => 'text',         'default' => null],
 *           ],
 *           'indexes' => [
 *               // Simple format (non-unique index):
 *               'idx_user_id'  => ['user_id'],
 *               // Associative format (supports unique):
 *               'uniq_label'   => ['columns' => ['label'], 'unique' => true],
 *               // Both formats may be mixed in one table.
 *           ],
 *           'foreign_keys' => [
 *               ['column' => 'user_id', 'references' => 'users', 'on' => 'id', 'on_delete' => 'CASCADE'],
 *           ],
 *       ],
 *   ]
 *
 * Special column type 'id_ref' always resolves to CHAR(36) regardless of driver,
 * matching the UUID-based primary key used for all module tables.
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class ModuleSchemaBuilder
{
    /**
     * Executes CREATE TABLE IF NOT EXISTS for every table in the schema definition.
     *
     * @param array<string, array<string, mixed>> $tables Map of table name → table definition.
     * @param PDO                                 $pdo    Active database connection.
     */
    public function build(array $tables, PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach ($tables as $tableName => $definition) {
            foreach ($this->statementsFor($tableName, $definition, $driver) as $sql) {
                $pdo->exec($sql);
            }
        }
    }

    /**
     * Generates all SQL statements needed to create one table.
     *
     * For MySQL, indexes and foreign keys are emitted inline inside CREATE TABLE.
     * For SQLite, indexes are emitted as separate CREATE INDEX IF NOT EXISTS statements.
     *
     * @param  string               $table      Table name.
     * @param  array<string, mixed> $definition Table definition array.
     * @param  string               $driver     PDO driver name ('mysql' or 'sqlite').
     * @return list<string>
     */
    public function statementsFor(string $table, array $definition, string $driver): array
    {
        $expanded    = $this->expandDefinition($definition);
        $columns     = (array) ($expanded['columns']      ?? []);
        $primary     = isset($expanded['primary']) ? (string) $expanded['primary'] : null;
        $indexes     = (array) ($expanded['indexes']      ?? []);
        $foreignKeys = (array) ($expanded['foreign_keys'] ?? []);

        $parts = [];

        foreach ($columns as $name => $col) {
            $parts[] = $this->columnDdl((string) $name, (array) $col, $driver);
        }

        if ($primary !== null) {
            $parts[] = "PRIMARY KEY (`{$primary}`)";
        }

        if ($driver === 'mysql') {
            foreach ($indexes as $indexName => $indexDef) {
                [$cols, $unique] = $this->parseIndexDef((array) $indexDef);
                $keyword         = $unique ? 'UNIQUE INDEX' : 'INDEX';
                $colList         = '`' . implode('`, `', $cols) . '`';
                $parts[]         = "{$keyword} `{$indexName}` ({$colList})";
            }

            foreach ($foreignKeys as $fk) {
                $parts[] = $this->foreignKeyDdl((array) $fk);
            }
        }

        $body   = "\n    " . implode(",\n    ", $parts) . "\n";
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $stmts  = ["CREATE TABLE IF NOT EXISTS `{$table}` ({$body}){$suffix}"];

        if ($driver === 'sqlite') {
            foreach ($indexes as $indexName => $indexDef) {
                [$cols, $unique] = $this->parseIndexDef((array) $indexDef);
                $keyword         = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
                $colList         = '`' . implode('`, `', $cols) . '`';
                $stmts[]         = "{$keyword} IF NOT EXISTS `{$indexName}` ON `{$table}` ({$colList})";
            }
        }

        return $stmts;
    }

    /**
     * Returns all declared column DDL fragments keyed by column name, after auto-injection.
     *
     * Used by SchemaStateManager to detect missing columns and generate ALTER TABLE statements.
     *
     * @param  array<string, mixed> $definition Table definition array.
     * @param  string               $driver     PDO driver name.
     * @return array<string, string> Map of column name → full DDL fragment (e.g. "`col` VARCHAR(255) NOT NULL").
     */
    public function declaredColumns(array $definition, string $driver): array
    {
        $expanded = $this->expandDefinition($definition);
        $result   = [];

        foreach ((array) ($expanded['columns'] ?? []) as $name => $col) {
            $result[(string) $name] = $this->columnDdl((string) $name, (array) $col, $driver);
        }

        return $result;
    }

    /**
     * Expands a raw table definition by injecting system columns.
     *
     * Injection rules:
     *   - 'id'         prepended as CHAR(36) NOT NULL (UUID PK) if not defined by the user.
     *   - 'created_at' appended as DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP if not defined.
     *   - 'updated_at' appended with ON UPDATE CURRENT_TIMESTAMP if 'timestamps' => true and not defined.
     *
     * User-defined columns always take priority over injected ones.
     *
     * @param  array<string, mixed> $definition Raw table definition.
     * @return array<string, mixed> Expanded definition with system columns merged in.
     */
    private function expandDefinition(array $definition): array
    {
        $userColumns = (array) ($definition['columns'] ?? []);
        $timestamps  = (bool)  ($definition['timestamps'] ?? false);

        $columns = [];

        if (!array_key_exists('id', $userColumns)) {
            $columns['id']          = ['type' => 'char(36)', 'null' => false];
            $definition['primary'] ??= 'id';
        }

        foreach ($userColumns as $name => $col) {
            $columns[(string) $name] = (array) $col;
        }

        if (!array_key_exists('created_at', $columns)) {
            $columns['created_at'] = ['type' => 'datetime', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'];
        }

        if ($timestamps && !array_key_exists('updated_at', $columns)) {
            $columns['updated_at'] = [
                'type'      => 'datetime',
                'null'      => false,
                'default'   => 'CURRENT_TIMESTAMP',
                'on_update' => 'CURRENT_TIMESTAMP',
            ];
        }

        $definition['columns'] = $columns;

        return $definition;
    }

    /**
     * Builds a single column definition fragment.
     *
     * @param  string               $name   Column name.
     * @param  array<string, mixed> $col    Column options.
     * @param  string               $driver PDO driver name.
     * @return string
     */
    private function columnDdl(string $name, array $col, string $driver): string
    {
        $type     = $this->mapType((string) ($col['type'] ?? 'varchar(255)'), $driver);
        $unsigned = ($col['unsigned'] ?? false) && $driver === 'mysql' ? ' UNSIGNED' : '';
        $null     = ($col['null'] ?? true) ? '' : ' NOT NULL';
        $ai       = ($col['auto_increment'] ?? false) && $driver === 'mysql' ? ' AUTO_INCREMENT' : '';
        $default  = $this->defaultFragment($col);
        $onUpdate = isset($col['on_update']) && $driver === 'mysql'
            ? ' ON UPDATE ' . strtoupper((string) $col['on_update'])
            : '';

        return "`{$name}` {$type}{$unsigned}{$null}{$ai}{$default}{$onUpdate}";
    }

    /**
     * Builds a FOREIGN KEY constraint fragment.
     *
     * @param  array<string, mixed> $fk Foreign key definition.
     * @return string
     */
    private function foreignKeyDdl(array $fk): string
    {
        $col      = (string) ($fk['column']    ?? '');
        $refTable = (string) ($fk['references'] ?? '');
        $refCol   = (string) ($fk['on']         ?? 'id');
        $onDelete = (string) ($fk['on_delete']  ?? 'RESTRICT');

        return "FOREIGN KEY (`{$col}`) REFERENCES `{$refTable}`(`{$refCol}`) ON DELETE {$onDelete}";
    }

    /**
     * Generates the DEFAULT fragment for a column definition.
     *
     * Raw SQL expressions (CURRENT_TIMESTAMP, NULL) are emitted unquoted.
     *
     * @param  array<string, mixed> $col
     * @return string
     */
    private function defaultFragment(array $col): string
    {
        if (!array_key_exists('default', $col)) {
            return '';
        }

        $val = $col['default'];

        if ($val === null) {
            return ' DEFAULT NULL';
        }

        $raw = strtoupper((string) $val);
        if ($raw === 'CURRENT_TIMESTAMP' || $raw === 'NOW()') {
            return " DEFAULT {$raw}";
        }

        return " DEFAULT '" . addslashes((string) $val) . "'";
    }

    /**
     * Parses an index definition into a column list and a unique flag.
     *
     * Supports two formats:
     *   - Simple (legacy): `['col1', 'col2']`             — non-unique
     *   - Associative:     `['columns' => ['col1'], 'unique' => true]`
     *
     * @param  array<mixed> $indexDef Raw value from the 'indexes' map.
     * @return array{0: list<string>, 1: bool}
     */
    private function parseIndexDef(array $indexDef): array
    {
        if (isset($indexDef['columns'])) {
            return [(array) $indexDef['columns'], (bool) ($indexDef['unique'] ?? false)];
        }

        return [$indexDef, false];
    }

    /**
     * Maps a generic type name to a driver-specific SQL type.
     *
     * 'id_ref' always resolves to CHAR(36) to match the UUID-based primary key convention.
     * SQLite uses type affinity: integers → INTEGER, strings → TEXT, floats → REAL.
     *
     * @param  string $type   Declared type from the schema array (e.g. 'int', 'varchar(255)', 'id_ref').
     * @param  string $driver PDO driver name.
     * @return string
     */
    private function mapType(string $type, string $driver): string
    {
        if ($type === 'id_ref') {
            return 'CHAR(36)';
        }

        if ($driver !== 'sqlite') {
            return strtoupper($type);
        }

        $lower = strtolower($type);

        if (preg_match('/^(tiny|small|medium|big)?int/', $lower)) {
            return 'INTEGER';
        }
        if (preg_match('/^(var)?char|^text|^(tiny|medium|long)text|^json/', $lower)) {
            return 'TEXT';
        }
        if (preg_match('/^(float|double|decimal|numeric|real)/', $lower)) {
            return 'REAL';
        }
        if (preg_match('/^(datetime|timestamp|date|time)/', $lower)) {
            return 'TEXT';
        }
        if (preg_match('/^(tiny|medium|long)?blob/', $lower)) {
            return 'BLOB';
        }
        if (preg_match('/^(var)?binary/', $lower)) {
            return 'BLOB';
        }

        return strtoupper($type);
    }
}
