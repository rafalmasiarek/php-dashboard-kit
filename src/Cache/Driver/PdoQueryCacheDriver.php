<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache\Driver;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use rafalmasiarek\DashboardKit\Cache\QueryCacheDriverInterface;

/**
 * Database-backed tag-based query result cache stored in `_query_cache`.
 *
 * Uses a raw PDO connection (never CachingPdo) to avoid a circular dependency.
 * The backing table is created automatically on first use if absent. Each row
 * stores the serialized row set (payload), a JSON array of table tags, and an
 * expiry timestamp. Invalidation deletes all rows whose tags overlap with the
 * given set — no version counters, no stale-key accumulation.
 *
 * MySQL and SQLite are both supported; the driver auto-detects the engine from
 * PDO::ATTR_DRIVER_NAME and adjusts its queries accordingly.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
final class PdoQueryCacheDriver implements QueryCacheDriverInterface
{
    /**
     * Detected PDO driver name ('mysql' or 'sqlite').
     *
     * @var string
     */
    private string $driver;

    /**
     * @param PDO $pdo Raw database connection. Must not be a CachingPdo instance.
     */
    public function __construct(private readonly PDO $pdo)
    {
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->ensureTable();
    }

    /**
     * Retrieves cached rows by key if the entry exists and has not expired.
     *
     * @param  string          $key Cache key.
     * @return array<mixed>|null Cached row set, or null on miss or expiry.
     */
    public function get(string $key): ?array
    {
        $nowExpr = $this->driver === 'mysql' ? 'NOW()' : "datetime('now')";

        $stmt = $this->pdo->prepare(
            "SELECT payload FROM `_query_cache` WHERE cache_key = ? AND expires_at > {$nowExpr} LIMIT 1"
        );
        $stmt->execute([$key]);

        $payload = $stmt->fetchColumn();

        if ($payload === false) {
            return null;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded['rows'] ?? null) ? $decoded['rows'] : null;
    }

    /**
     * Stores a row set with associated tags and a time-to-live.
     *
     * The payload JSON embeds diagnostic metadata (version, timestamps, TTL,
     * table list, row count, column names) alongside the rows for introspection.
     * The tags column is stored separately as a JSON array for SQL-level filtering
     * during invalidation.
     *
     * @param string       $key  Cache key.
     * @param array<mixed> $rows Row set to cache.
     * @param int          $ttl  Time-to-live in seconds.
     * @param string       ...$tags Table names read by the cached query.
     */
    public function set(string $key, array $rows, int $ttl, string ...$tags): void
    {
        $now       = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttl} seconds");

        $payload = json_encode([
            'rows' => $rows,
            'meta' => [
                'v'          => 1,
                'cached_at'  => $now->format(DateTimeInterface::ATOM),
                'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
                'ttl'        => $ttl,
                'tables'     => array_values($tags),
                'row_count'  => count($rows),
                'columns'    => is_array($rows[0] ?? null) ? array_keys($rows[0]) : [],
            ],
        ]);

        $tagsJson        = json_encode(array_values($tags));
        $expiresAtColumn = date('Y-m-d H:i:s', time() + $ttl);

        if ($this->driver === 'mysql') {
            $this->pdo->prepare(
                'INSERT INTO `_query_cache` (cache_key, payload, tags, expires_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     payload    = VALUES(payload),
                     tags       = VALUES(tags),
                     expires_at = VALUES(expires_at)'
            )->execute([$key, $payload, $tagsJson, $expiresAtColumn]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO `_query_cache` (cache_key, payload, tags, expires_at)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(cache_key) DO UPDATE SET
                     payload    = excluded.payload,
                     tags       = excluded.tags,
                     expires_at = excluded.expires_at'
            )->execute([$key, $payload, $tagsJson, $expiresAtColumn]);
        }
    }

    /**
     * Deletes all cache entries tagged with any of the given table names.
     *
     * No-op when called with no tags, preventing accidental full-table deletes.
     *
     * @param string ...$tags Table names to invalidate.
     */
    public function invalidate(string ...$tags): void
    {
        if ($tags === []) {
            return;
        }

        if ($this->driver === 'mysql') {
            $this->pdo->prepare(
                'DELETE FROM `_query_cache` WHERE JSON_OVERLAPS(tags, ?)'
            )->execute([json_encode(array_values($tags))]);
        } else {
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $this->pdo->prepare(
                "DELETE FROM `_query_cache` WHERE EXISTS (
                    SELECT 1 FROM json_each(tags) WHERE value IN ({$placeholders})
                )"
            )->execute(array_values($tags));
        }
    }

    /**
     * Removes all entries from the cache table unconditionally.
     */
    public function flush(): void
    {
        $this->pdo->exec('DELETE FROM `_query_cache`');
    }

    /**
     * Deletes expired cache entries and returns the number of rows removed.
     *
     * Wire this into any scheduled task (e.g. daily cron via dashboard-kit-scheduler)
     * to prevent unbounded table growth from naturally expiring entries that were
     * never explicitly invalidated.
     *
     * @return int Number of deleted rows.
     */
    public function prune(): int
    {
        $nowExpr = $this->driver === 'mysql' ? 'NOW()' : "datetime('now')";

        $stmt = $this->pdo->prepare(
            "DELETE FROM `_query_cache` WHERE expires_at < {$nowExpr}"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Creates or migrates the `_query_cache` table.
     *
     * If the table is absent, it is created with the current schema. If the table
     * exists but is missing the `tags` column (schema from the pre-tag-based version),
     * it is dropped and recreated. Cache data is ephemeral, so the drop is safe.
     */
    private function ensureTable(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_query_cache` (
                    `cache_key`  VARCHAR(32)  NOT NULL,
                    `payload`    LONGTEXT     NOT NULL,
                    `tags`       JSON         NOT NULL,
                    `expires_at` DATETIME     NOT NULL,
                    PRIMARY KEY (`cache_key`),
                    INDEX `idx_expires_at` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $hasTagsColumn = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '_query_cache'
                   AND COLUMN_NAME  = 'tags'"
            )->fetchColumn() > 0;

            if (!$hasTagsColumn) {
                $this->pdo->exec('DROP TABLE `_query_cache`');
                $this->pdo->exec(
                    "CREATE TABLE `_query_cache` (
                        `cache_key`  VARCHAR(32)  NOT NULL,
                        `payload`    LONGTEXT     NOT NULL,
                        `tags`       JSON         NOT NULL,
                        `expires_at` DATETIME     NOT NULL,
                        PRIMARY KEY (`cache_key`),
                        INDEX `idx_expires_at` (`expires_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            }
        } else {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `_query_cache` (
                    `cache_key`  TEXT NOT NULL PRIMARY KEY,
                    `payload`    TEXT NOT NULL,
                    `tags`       TEXT NOT NULL,
                    `expires_at` TEXT NOT NULL
                )"
            );

            $columns = $this->pdo->query("PRAGMA table_info(`_query_cache`)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('tags', $columns, true)) {
                $this->pdo->exec('DROP TABLE `_query_cache`');
                $this->pdo->exec(
                    "CREATE TABLE `_query_cache` (
                        `cache_key`  TEXT NOT NULL PRIMARY KEY,
                        `payload`    TEXT NOT NULL,
                        `tags`       TEXT NOT NULL,
                        `expires_at` TEXT NOT NULL
                    )"
                );
            }
        }
    }
}
