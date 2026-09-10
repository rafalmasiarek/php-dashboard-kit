<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache;

use PDO;
use PDOStatement;

/**
 * PDOStatement subclass that transparently caches SELECT results using tag-based invalidation.
 *
 * Instantiated automatically by CachingPdo via PDO::ATTR_STATEMENT_CLASS.
 * Never constructed directly.
 *
 * Behaviour per statement type:
 *   SELECT  — checks the driver before executing; on miss, executes and caches
 *             the fetchAll() result keyed by sql + params (no version embedding).
 *   WRITE   — executes normally, then invalidates all cache entries tagged with
 *             the affected table names.
 *   SYSTEM  — queries touching _query_cache or _schema_state are always passed
 *             through to PDO unchanged (no caching, no invalidation).
 *   TRANSACTION-ACTIVE — bypasses cache entirely; write table names are appended to
 *             TransactionState::$dirtyTables for deferred invalidation on commit.
 *
 * Only fetchAll() results are cached. fetch() and fetchColumn() always hit the DB.
 * The cache key is md5(sql + params) — no version numbers embedded.
 * Invalidation is tag-driven: a write to table T deletes every entry tagged with T.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
final class CachingStatement extends PDOStatement
{
    /**
     * Tables whose queries always bypass the cache to avoid circular dependency.
     *
     * @var list<string>
     */
    private const SYSTEM_TABLES = ['_query_cache', '_schema_state'];

    /**
     * Values captured by bindValue() calls, used to build the cache key when
     * execute() is called without an explicit $params array.
     *
     * @var array<string|int, mixed>
     */
    private array $boundValues = [];

    /**
     * Populated on a cache hit; consumed and cleared by fetchAll().
     *
     * @var list<mixed>|null
     */
    private ?array $hitResult = null;

    /**
     * Cache key waiting to be written after a SELECT miss.
     * Set in execute(), consumed in fetchAll().
     *
     * @var string|null
     */
    private ?string $pendingKey = null;

    /**
     * Table tags associated with the pending cache write.
     * Set alongside $pendingKey in execute(), consumed in fetchAll().
     *
     * @var list<string>|null
     */
    private ?array $pendingTags = null;

    /**
     * True when the last execute() was served from cache.
     *
     * @var bool
     */
    private bool $fromCache = false;

    /**
     * @param QueryCacheDriverInterface $driver     Tag-based cache driver.
     * @param int                       $defaultTtl Cache entry TTL in seconds.
     * @param SqlTableExtractor         $extractor  SQL table-name parser.
     * @param TransactionState          $txState    Shared transaction coordination bag from CachingPdo.
     */
    protected function __construct(
        private readonly QueryCacheDriverInterface $driver,
        private readonly int                       $defaultTtl,
        private readonly SqlTableExtractor         $extractor,
        private readonly TransactionState          $txState,
    ) {}

    /**
     * Intercepts parameter binding to track values for cache-key construction.
     *
     * @param string|int $param Column name or positional index.
     * @param mixed      $value Value to bind.
     * @param int        $type  PDO::PARAM_* constant.
     * @return bool
     */
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    /**
     * Executes the statement with transparent cache read-through for SELECTs.
     *
     * @param  array<mixed>|null $params Bound values, overrides any prior bindValue() calls.
     * @return bool
     */
    public function execute(?array $params = null): bool
    {
        $this->reset();

        $info = $this->extractor->extract($this->queryString);

        // System-table queries always go straight to PDO.
        if (array_intersect($info['tables'], self::SYSTEM_TABLES) !== []) {
            return parent::execute($params);
        }

        // Transaction-active: bypass cache entirely; accumulate dirty tables for deferred invalidation.
        if ($this->txState->active) {
            $result = parent::execute($params);
            if ($result && $info['is_write'] && $info['tables'] !== []) {
                array_push($this->txState->dirtyTables, ...$info['tables']);
            }
            return $result;
        }

        // Write: execute and invalidate cache entries for affected tables.
        if ($info['is_write']) {
            $result = parent::execute($params);
            if ($result && $info['tables'] !== []) {
                $this->driver->invalidate(...$info['tables']);
            }
            return $result;
        }

        // SELECT without detectable tables: skip cache, execute normally.
        if ($info['tables'] === []) {
            return parent::execute($params);
        }

        // SELECT with known tables: tag-keyed cache lookup.
        $effectiveParams = $params ?? $this->boundValues;
        $key             = $this->buildKey($this->queryString, $effectiveParams);
        $cached          = $this->driver->get($key);

        if ($cached !== null) {
            $this->hitResult = $cached;
            $this->fromCache = true;
            return true;
        }

        $this->pendingKey  = $key;
        $this->pendingTags = $info['tables'];
        return parent::execute($params);
    }

    /**
     * Returns cached rows on a hit, or fetches and stores them on a miss.
     *
     * @param  int   $mode  PDO::FETCH_* mode.
     * @param  mixed ...$args Additional fetch arguments forwarded to parent.
     * @return list<mixed>
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->fromCache) {
            $result          = $this->hitResult;
            $this->hitResult = null;
            $this->fromCache = false;
            return $result ?? [];
        }

        $result = parent::fetchAll($mode, ...$args);

        if ($this->pendingKey !== null) {
            $this->driver->set($this->pendingKey, $result, $this->defaultTtl, ...($this->pendingTags ?? []));
            $this->pendingKey  = null;
            $this->pendingTags = null;
        }

        return $result;
    }

    /**
     * Builds a deterministic cache key from the SQL and its bound parameters.
     *
     * No version numbers are embedded — invalidation is handled by tag deletion
     * rather than key rotation, so the key remains stable across writes.
     *
     * @param  string        $sql
     * @param  array<mixed>  $params
     * @return string
     */
    private function buildKey(string $sql, array $params): string
    {
        return md5($sql . '|' . serialize($params));
    }

    /**
     * Resets all per-execution state before each execute() call.
     */
    private function reset(): void
    {
        $this->hitResult   = null;
        $this->pendingKey  = null;
        $this->pendingTags = null;
        $this->fromCache   = false;
    }
}
