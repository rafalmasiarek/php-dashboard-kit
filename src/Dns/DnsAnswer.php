<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Dns;

/**
 * Result of an A record query, including the TTL to honor for caching.
 *
 * @package rafalmasiarek\DashboardKit\Dns
 */
final readonly class DnsAnswer
{
    /**
     * @param list<string> $records IPv4 addresses from the answer. Empty when the
     *                               hostname has no A record.
     * @param int          $ttl     Seconds this answer may be cached for. 0 when
     *                               unknown — callers should treat that as "do not cache".
     */
    public function __construct(
        public array $records,
        public int $ttl,
    ) {
    }
}
