<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use PDO;

/**
 * Runs declarative seed definitions against the live database.
 *
 * Seeds are idempotent: each row is skipped when a matching record already
 * exists (matched by the 'match' key). Only missing rows are inserted.
 *
 * ## References between seeds
 *
 * Seeds run in definition order. A row may declare a `#ref` alias that makes
 * its resolved `id` available to later rows via `@ref:alias.field` placeholders.
 *
 *   'users' => [
 *       'rows' => [
 *           ['email' => 'admin@example.com', ..., '#ref' => 'admin'],
 *       ],
 *   ],
 *   'tibia_user_prefs' => [
 *       'rows' => [
 *           ['user_id' => '@ref:admin.id', 'notify_daily' => 1],
 *       ],
 *   ],
 *
 * `#ref` is stripped before insertion. `@ref:alias.field` is resolved against
 * the stored column map of the referenced row (including the generated `id`).
 * If the ref alias is unknown the placeholder resolves to `null`.
 *
 * ## Format
 *
 * <code>
 *   'seed' => [
 *       'users' => [
 *           'match'   => 'email',        // unique column to check before inserting
 *           'with_id' => true,           // auto-generate UUID for id if absent (default: true)
 *           'rows'    => [
 *               [
 *                   'email'         => 'admin@example.com',
 *                   'role'          => 'admin',
 *                   'active'        => 1,
 *                   'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
 *                   '#ref'          => 'admin',
 *               ],
 *           ],
 *       ],
 *       'api_scopes' => [
 *           'match'   => 'name',
 *           'with_id' => false,          // table uses AUTO_INCREMENT — skip UUID injection
 *           'rows'    => [
 *               ['name' => 'shorts:read',  'description' => 'Read short links'],
 *               ['name' => 'shorts:write', 'description' => 'Manage short links'],
 *           ],
 *       ],
 *   ],
 * </code>
 *
 * 'with_id' defaults to true. Set it to false for tables whose id column is
 * AUTO_INCREMENT (i.e. tables that declare id explicitly in their schema columns
 * instead of relying on the framework's UUID auto-injection).
 *
 * 'created_at' is never auto-injected: tables created by ModuleSchemaBuilder
 * always have DEFAULT CURRENT_TIMESTAMP on that column, so it is optional in
 * seed rows and the database fills it in automatically.
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class SeedRunner
{
    /**
     * @param PDO    $pdo         Raw database connection (bypasses CachingPdo).
     * @param string $tablePrefix Optional prefix applied to every table name. Default: ''.
     */
    public function __construct(
        private readonly PDO    $pdo,
        private readonly string $tablePrefix = '',
    ) {}

    /**
     * Execute all seed definitions in order.
     *
     * Seeds run in PHP array order. Rows tagged with '#ref' register their
     * resolved id so that subsequent rows can use '@ref:alias.field' placeholders.
     *
     * @param array<string, array{
     *     match?:   string,
     *     with_id?: bool,
     *     rows:     list<array<string, mixed>>
     * }> $seeds Map of table name → seed definition.
     */
    public function run(array $seeds): void
    {
        /** @var array<string, array<string, mixed>> $refs alias → resolved row columns */
        $refs = [];

        foreach ($seeds as $table => $def) {
            $prefixedTable = $this->tablePrefix . (string) $table;
            $matchKey      = (string) ($def['match']   ?? '');
            $withId        = (bool)   ($def['with_id'] ?? true);
            $rows          = (array)  ($def['rows']    ?? []);

            foreach ($rows as $row) {
                $row = (array) $row;

                // Extract the optional ref alias before any DB work.
                $refAlias = isset($row['#ref']) ? (string) $row['#ref'] : null;
                unset($row['#ref']);

                // Resolve '@ref:alias.field' placeholders against previously registered rows.
                foreach ($row as $col => $val) {
                    if (is_string($val) && str_starts_with($val, '@ref:')) {
                        $parts     = explode('.', substr($val, 5), 2);
                        $alias     = $parts[0];
                        $field     = $parts[1] ?? 'id';
                        $row[$col] = $refs[$alias][$field] ?? null;
                    }
                }

                if ($withId && !array_key_exists('id', $row)) {
                    $row = array_merge(['id' => $this->generateUuid()], $row);
                }

                $ignoreduplicates = (bool) ($def['ignore_duplicates'] ?? false);
                $resolvedId       = $this->insertIfMissing($prefixedTable, $row, $matchKey, $withId, $ignoreduplicates);

                if ($refAlias !== null) {
                    $refRow = $row;

                    if ($resolvedId !== null) {
                        $refRow['id'] = $resolvedId;
                    } elseif (!$withId && $matchKey !== '' && array_key_exists($matchKey, $row)) {
                        // AUTO_INCREMENT table — fetch the generated id so it can be used in later refs.
                        try {
                            $fetch = $this->pdo->prepare("SELECT `id` FROM `{$prefixedTable}` WHERE `{$matchKey}` = ?");
                            $fetch->execute([$row[$matchKey]]);
                            $fetched = $fetch->fetchColumn();
                            if ($fetched !== false) {
                                $refRow['id'] = (string) $fetched;
                            }
                        } catch (\Throwable) {
                            // Table has no id column — ref registered without it.
                        }
                    }

                    $refs[$refAlias] = $refRow;
                }
            }
        }
    }

    /**
     * Insert a row only when no matching record exists.
     *
     * When $withId is true the existence check fetches the existing id so it can
     * be registered as a ref. When $withId is false (AUTO_INCREMENT / no UUID id
     * column) COUNT(*) is used and the method returns null — those tables have
     * nothing to resolve via '@ref:'.
     *
     * When $matchKey is empty the check is skipped and the row is always inserted.
     *
     * @param string               $table    Target table name.
     * @param array<string, mixed> $row      Column → value map.
     * @param string               $matchKey Column name used for the existence check.
     * @param bool                 $withId   Whether the table has a UUID id column.
     *
     * @return string|null Resolved id of the row, or null when unavailable.
     */
    private function insertIfMissing(string $table, array $row, string $matchKey, bool $withId, bool $ignoreDuplicates = false): string|null
    {
        if ($matchKey !== '' && array_key_exists($matchKey, $row)) {
            if ($withId) {
                $check = $this->pdo->prepare(
                    "SELECT `id` FROM `{$table}` WHERE `{$matchKey}` = ?"
                );
                $check->execute([$row[$matchKey]]);
                $existing = $check->fetchColumn();

                if ($existing !== false) {
                    return (string) $existing;
                }
            } else {
                $check = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE `{$matchKey}` = ?"
                );
                $check->execute([$row[$matchKey]]);

                if ((int) $check->fetchColumn() > 0) {
                    return null;
                }
            }
        }

        $columns      = implode(', ', array_map(static fn($c) => "`{$c}`", array_keys($row)));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $verb         = $ignoreDuplicates ? 'INSERT IGNORE INTO' : 'INSERT INTO';

        $this->pdo->prepare(
            "{$verb} `{$table}` ({$columns}) VALUES ({$placeholders})"
        )->execute(array_values($row));

        return array_key_exists('id', $row) ? (string) $row['id'] : null;
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string Formatted as xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx.
     *
     * @throws \Random\RandomException On PRNG failure (PHP 8.2+).
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
