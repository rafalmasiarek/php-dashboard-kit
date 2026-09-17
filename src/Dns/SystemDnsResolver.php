<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Dns;

/**
 * Resolves A records via PHP's own system resolver (dns_get_record()).
 *
 * Pins nothing: no explicit nameserver, no custom timeout — whatever the host's
 * resolv.conf and PHP's default DNS timeout behavior already do. This is the
 * baseline DnsResolverInterface implementation; an app that needs an explicit
 * nameserver, a bounded timeout, or failover should provide its own.
 *
 * @package rafalmasiarek\DashboardKit\Dns
 */
final class SystemDnsResolver implements DnsResolverInterface
{
    /**
     * Resolves the A records for a hostname via the system resolver.
     *
     * @param  string $hostname Fully-qualified hostname to query.
     * @return DnsAnswer
     * @throws DnsQueryException When the system resolver reports a lookup failure.
     */
    public function resolveA(string $hostname): DnsAnswer
    {
        $records = @\dns_get_record($hostname, \DNS_A);

        if ($records === false) {
            throw new DnsQueryException("System resolver failed to query A records for \"{$hostname}\".");
        }

        $addresses = [];
        $ttl = 0;
        $ttlSet = false;

        foreach ($records as $record) {
            if (($record['type'] ?? null) !== 'A' || !isset($record['ip'])) {
                continue;
            }
            $addresses[] = (string) $record['ip'];
            $recordTtl = (int) ($record['ttl'] ?? 0);
            $ttl = $ttlSet ? \min($ttl, $recordTtl) : $recordTtl;
            $ttlSet = true;
        }

        return new DnsAnswer($addresses, $ttlSet ? $ttl : 0);
    }
}
