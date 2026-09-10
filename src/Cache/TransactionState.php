<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache;

/**
 * Shared mutable transaction state passed between CachingPdo and CachingStatement.
 *
 * Acts as a coordination bag: CachingPdo sets $active on begin/commit/rollback;
 * CachingStatement reads $active to decide whether to skip the cache and appends
 * to $dirtyTables instead of bumping immediately. CachingPdo flushes accumulated
 * dirty tables to TableVersionTracker on commit and discards them on rollback.
 *
 * Passed by object handle so mutations in CachingPdo are immediately visible
 * in every CachingStatement prepared from the same connection.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
final class TransactionState
{
    /**
     * Whether a PDO transaction is currently active.
     *
     * @var bool
     */
    public bool $active = false;

    /**
     * Tables touched by writes during the active transaction.
     *
     * Accumulated by CachingStatement and CachingPdo::exec() during a transaction.
     * Flushed to TableVersionTracker on commit; discarded on rollback.
     *
     * @var list<string>
     */
    public array $dirtyTables = [];
}
