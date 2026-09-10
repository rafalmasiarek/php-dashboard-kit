<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache;

use PDO;

/**
 * PDO subclass that caches SELECT query results with tag-based invalidation.
 *
 * Drop-in replacement for PDO: type-compatible (extends PDO), registered
 * as PDO::class in the DI container. All prepared statements automatically
 * become CachingStatement instances via PDO::ATTR_STATEMENT_CLASS.
 *
 * exec() is overridden to invalidate tagged cache entries after data-modifying
 * statements that bypass prepare/execute (e.g. raw INSERT/UPDATE/DELETE strings).
 *
 * The backing driver must be constructed against a separate raw PDO connection
 * ('pdo.raw' in the container) to avoid a circular dependency — the driver itself
 * queries _query_cache, which is already excluded from caching in CachingStatement
 * (SYSTEM_TABLES).
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
class CachingPdo extends PDO
{
    /**
     * Shared SQL table name parser reused across all exec() calls.
     *
     * @var SqlTableExtractor
     */
    private SqlTableExtractor $extractor;

    /**
     * Shared transaction state bag coordinating this connection and all its prepared statements.
     *
     * @var TransactionState
     */
    private TransactionState $txState;

    /**
     * @param string                    $dsn        PDO DSN string.
     * @param string                    $username   Database user.
     * @param string                    $password   Database password.
     * @param array<int, mixed>         $options    PDO driver options.
     * @param QueryCacheDriverInterface $driver     Tag-based cache driver (must use raw PDO internally).
     * @param int                       $defaultTtl Default cache TTL in seconds. Default: 300.
     * @param string                    $tablePrefix Optional prefix prepended by table(). Default: ''.
     */
    public function __construct(
        string $dsn,
        string $username,
        #[\SensitiveParameter] string $password,
        array  $options,
        private readonly QueryCacheDriverInterface $driver,
        private readonly int                       $defaultTtl  = 300,
        private readonly string                    $tablePrefix = '',
    ) {
        parent::__construct($dsn, $username, $password, $options);

        $this->extractor = new SqlTableExtractor();
        $this->txState   = new TransactionState();

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            CachingStatement::class,
            [$this->driver, $this->defaultTtl, $this->extractor, $this->txState],
        ]);
    }

    /**
     * Returns the prefixed table name for use in raw SQL queries.
     *
     * Use this in every handler that builds SQL manually so that the configured
     * table prefix is applied consistently:
     *   $db->prepare("SELECT * FROM `" . $db->table('short_links') . "` WHERE ...")
     *
     * When no prefix is configured, the name is returned as-is.
     *
     * @param  string $name Logical (un-prefixed) table name.
     * @return string Prefixed table name ready for use in SQL.
     */
    public function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    /**
     * Executes a raw SQL statement and invalidates cache entries for write statements.
     *
     * DDL statements (CREATE, ALTER, DROP) are executed unchanged; they are not
     * detected as writes and do not trigger invalidation.
     *
     * When a transaction is active, dirty table names are accumulated in
     * TransactionState rather than invalidated immediately; CachingPdo::commit()
     * flushes them once the transaction is durably written.
     *
     * @param  string    $statement SQL to execute.
     * @return int|false Number of affected rows, or false on failure.
     */
    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);

        if ($result !== false) {
            $info = $this->extractor->extract($statement);
            if ($info['is_write'] && $info['tables'] !== []) {
                if ($this->txState->active) {
                    array_push($this->txState->dirtyTables, ...$info['tables']);
                } else {
                    $this->driver->invalidate(...$info['tables']);
                }
            }
        }

        return $result;
    }

    /**
     * Starts a PDO transaction and enables transaction-mode on the shared state.
     *
     * While the transaction is active, CachingStatement will bypass the query cache
     * on reads and accumulate write table names in TransactionState::$dirtyTables
     * rather than invalidating immediately.
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        $result = parent::beginTransaction();
        if ($result) {
            $this->txState->active      = true;
            $this->txState->dirtyTables = [];
        }
        return $result;
    }

    /**
     * Commits the active transaction and invalidates cache entries for all tables written during it.
     *
     * Invalidation is deferred until commit so that concurrent readers only see the
     * cache purge after the transaction is durably written, not mid-flight.
     *
     * @return bool
     */
    public function commit(): bool
    {
        $result = parent::commit();
        $dirty  = array_unique($this->txState->dirtyTables);
        $this->txState->active      = false;
        $this->txState->dirtyTables = [];
        if ($result && $dirty !== []) {
            $this->driver->invalidate(...$dirty);
        }
        return $result;
    }

    /**
     * Rolls back the active transaction and discards accumulated dirty-table tracking.
     *
     * No invalidation is issued — rolled-back writes never reached the DB, so
     * cached SELECT results remain valid and no cache purge is needed.
     *
     * @return bool
     */
    public function rollBack(): bool
    {
        $result = parent::rollBack();
        $this->txState->active      = false;
        $this->txState->dirtyTables = [];
        return $result;
    }
}
