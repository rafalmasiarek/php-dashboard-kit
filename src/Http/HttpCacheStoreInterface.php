<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Minimal storage contract for CachingHttpClient — deliberately not PSR-16/PSR-6,
 * since this only ever needs "get one entry" / "set one entry with a TTL", and a
 * narrower interface is easier to implement against an arbitrary backend (a
 * single SQLite table, a Redis instance, a directory of files, ...) without
 * dragging in a PSR cache dependency for every consumer of this package.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
interface HttpCacheStoreInterface
{
    /**
     * Returns the stored entry regardless of whether it's still fresh — CachingHttpClient
     * decides that itself (comparing the entry's own 'expires_at' against the current
     * time) because an expired entry's validators (etag/last_modified) are still needed
     * to revalidate it, not just to know it's stale.
     *
     * @param  string $key
     * @return array<string, mixed>|null Null only when no entry exists for this key at all.
     */
    public function get(string $key): ?array;

    /**
     * @param  string               $key
     * @param  array<string, mixed> $entry
     * @param  int                  $ttl   Seconds until this entry should be treated as expired.
     * @return void
     */
    public function set(string $key, array $entry, int $ttl): void;
}
