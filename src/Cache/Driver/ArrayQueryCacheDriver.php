<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache\Driver;

use rafalmasiarek\DashboardKit\Cache\QueryCacheDriverInterface;

/**
 * In-memory per-request query cache driver backed by a plain PHP array.
 *
 * Entries exist only for the lifetime of the current request and are never
 * persisted. Useful for tests, for reducing repeated identical queries within
 * a single request when a persistent cache backend is unavailable, or as a
 * first-level cache in front of a slower driver.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
final class ArrayQueryCacheDriver implements QueryCacheDriverInterface
{
    /**
     * Internal store keyed by cache key.
     *
     * Each entry: ['rows' => list<mixed>, 'tags' => list<string>, 'expires_at' => int]
     *
     * @var array<string, array{rows: array<mixed>, tags: list<string>, expires_at: int}>
     */
    private array $store = [];

    /**
     * Returns cached rows if the entry exists and has not expired.
     *
     * @param  string          $key Cache key.
     * @return array<mixed>|null Cached row set, or null on miss or expiry.
     */
    public function get(string $key): ?array
    {
        $entry = $this->store[$key] ?? null;

        if ($entry === null || time() >= $entry['expires_at']) {
            return null;
        }

        return $entry['rows'];
    }

    /**
     * Stores a row set with the given tags and TTL.
     *
     * @param string       $key  Cache key.
     * @param array<mixed> $rows Row set to cache.
     * @param int          $ttl  Time-to-live in seconds.
     * @param string       ...$tags Table names read by the cached query.
     */
    public function set(string $key, array $rows, int $ttl, string ...$tags): void
    {
        $this->store[$key] = [
            'rows'       => $rows,
            'tags'       => array_values($tags),
            'expires_at' => time() + $ttl,
        ];
    }

    /**
     * Removes all entries whose tag set intersects with the given table names.
     *
     * @param string ...$tags Table names to invalidate.
     */
    public function invalidate(string ...$tags): void
    {
        if ($tags === []) {
            return;
        }

        $tagSet = array_flip($tags);

        foreach ($this->store as $key => $entry) {
            foreach ($entry['tags'] as $entryTag) {
                if (isset($tagSet[$entryTag])) {
                    unset($this->store[$key]);
                    break;
                }
            }
        }
    }

    /**
     * Removes all entries from the in-memory store.
     */
    public function flush(): void
    {
        $this->store = [];
    }
}
