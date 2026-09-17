<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Dns;

/**
 * Resolves A records for a hostname.
 *
 * @package rafalmasiarek\DashboardKit\Dns
 */
interface DnsResolverInterface
{
    /**
     * Resolves the A records for a hostname.
     *
     * @param  string $hostname Fully-qualified hostname to query.
     * @return DnsAnswer
     * @throws DnsQueryException When the query cannot be completed.
     */
    public function resolveA(string $hostname): DnsAnswer;
}
