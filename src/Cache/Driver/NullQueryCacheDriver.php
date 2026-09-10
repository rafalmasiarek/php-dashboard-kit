<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache\Driver;

use rafalmasiarek\DashboardKit\Cache\QueryCacheDriverInterface;

/**
 * No-op query cache driver that never stores or returns anything.
 *
 * Useful in environments where caching is undesirable (e.g. CLI commands,
 * test suites, or deployments without a suitable cache backend). Every read
 * returns null (cache miss), and write operations are silently discarded.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
final class NullQueryCacheDriver implements QueryCacheDriverInterface
{
    /**
     * Always reports a cache miss.
     *
     * @param  string     $key Cache key.
     * @return array<mixed>|null Always null.
     */
    public function get(string $key): ?array
    {
        return null;
    }

    /**
     * Discards the row set without storing it.
     *
     * @param string       $key  Cache key.
     * @param array<mixed> $rows Row set to discard.
     * @param int          $ttl  Time-to-live in seconds (ignored).
     * @param string       ...$tags Table names (ignored).
     */
    public function set(string $key, array $rows, int $ttl, string ...$tags): void
    {
    }

    /**
     * No-op invalidation.
     *
     * @param string ...$tags Table names (ignored).
     */
    public function invalidate(string ...$tags): void
    {
    }

    /**
     * No-op flush.
     */
    public function flush(): void
    {
    }
}
