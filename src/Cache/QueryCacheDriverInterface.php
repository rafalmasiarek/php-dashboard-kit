<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache;

/**
 * Contract for tag-based query result cache drivers.
 *
 * Each SELECT result is stored under a key derived from the SQL and its bound
 * parameters, tagged with the names of the tables the query reads. On any write
 * to a table, the driver deletes every entry tagged with that table name —
 * providing automatic, targeted cache invalidation without version counters.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
interface QueryCacheDriverInterface
{
    /**
     * Retrieves cached rows by key.
     *
     * Returns null on a cache miss (entry absent or expired).
     *
     * @param  string     $key Cache key produced by CachingStatement::buildKey().
     * @return array<mixed>|null Cached row set, or null on miss.
     */
    public function get(string $key): ?array;

    /**
     * Stores a row set under the given key with the supplied tags and TTL.
     *
     * If an entry already exists for the key, it is overwritten.
     *
     * @param string    $key  Cache key.
     * @param array<mixed> $rows Row set returned by fetchAll().
     * @param int       $ttl  Time-to-live in seconds.
     * @param string    ...$tags Table names read by the cached query.
     */
    public function set(string $key, array $rows, int $ttl, string ...$tags): void;

    /**
     * Deletes all cache entries tagged with any of the given table names.
     *
     * Called after every write (INSERT / UPDATE / DELETE / REPLACE) outside a
     * transaction, and once per transaction on commit with the union of all
     * tables written during that transaction.
     *
     * @param string ...$tags Table names to invalidate.
     */
    public function invalidate(string ...$tags): void;

    /**
     * Removes all entries from the cache store unconditionally.
     *
     * Useful for admin panel "clear cache" actions or test teardown.
     */
    public function flush(): void;
}
