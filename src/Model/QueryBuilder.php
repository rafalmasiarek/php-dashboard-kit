<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Model;

use PDO;

/**
 * Fluent SELECT query builder for a single model class.
 *
 * Created by Model::where() and returned for method chaining. Column names
 * are interpolated directly into SQL — callers must not pass user-supplied
 * column names. Values are always bound as prepared-statement parameters.
 *
 * @package rafalmasiarek\DashboardKit\Model
 */
final class QueryBuilder
{
    /**
     * WHERE conditions accumulated via where() calls.
     *
     * @var list<array{column: string, operator: string, value: mixed}>
     */
    private array $wheres = [];

    /**
     * @var int|null LIMIT value; null = no limit.
     */
    private ?int $limitVal = null;

    /**
     * @var int|null OFFSET value; null = no offset.
     */
    private ?int $offsetVal = null;

    /**
     * ORDER BY fragments, e.g. ['`name` ASC', '`created_at` DESC'].
     *
     * @var list<string>
     */
    private array $orderBys = [];

    /**
     * Whether soft-deleted rows are included. Set by withTrashed()/onlyTrashed().
     *
     * @var bool
     */
    private bool $includeTrashed = false;

    /**
     * Whether only soft-deleted rows should match. Set by onlyTrashed().
     *
     * @var bool
     */
    private bool $onlyTrashed = false;

    /**
     * @param string $modelClass Fully-qualified model class name.
     * @param PDO    $pdo        Active database connection.
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly PDO    $pdo,
    ) {}

    /**
     * Includes soft-deleted rows in the result, alongside normal ones.
     *
     * @return static
     */
    public function withTrashed(): static
    {
        $this->includeTrashed = true;
        return $this;
    }

    /**
     * Restricts the result to soft-deleted rows only.
     *
     * @return static
     */
    public function onlyTrashed(): static
    {
        $this->includeTrashed = true;
        $this->onlyTrashed    = true;
        return $this;
    }

    /**
     * Adds a WHERE condition. Multiple calls are combined with AND.
     *
     * When called with two arguments, '=' is assumed as the operator:
     *   ->where('active', 1)          → WHERE `active` = ?
     *   ->where('role', '!=', 'user') → WHERE `role` != ?
     *
     * @param  string $column          Column name (trusted, not user input).
     * @param  mixed  $operatorOrValue Operator string when $value is given; bound value otherwise.
     * @param  mixed  $value           Bound value when an explicit operator is given.
     * @return static
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $boundValue] = $value !== null
            ? [(string) $operatorOrValue, $value]
            : ['=', $operatorOrValue];

        $this->wheres[] = ['column' => $column, 'operator' => $operator, 'value' => $boundValue];
        return $this;
    }

    /**
     * Sets the maximum number of rows to return.
     *
     * @param  int $limit
     * @return static
     */
    public function limit(int $limit): static
    {
        $this->limitVal = $limit;
        return $this;
    }

    /**
     * Sets the row offset for pagination.
     *
     * @param  int $offset
     * @return static
     */
    public function offset(int $offset): static
    {
        $this->offsetVal = $offset;
        return $this;
    }

    /**
     * Adds an ORDER BY clause.
     *
     * @param  string $column    Column name (trusted, not user input).
     * @param  string $direction 'ASC' or 'DESC'. Defaults to 'ASC'.
     * @return static
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBys[] = '`' . $column . '` ' . ($direction === 'DESC' ? 'DESC' : 'ASC');
        return $this;
    }

    /**
     * Executes the SELECT and returns a collection of model instances.
     *
     * @return Collection
     */
    public function get(): Collection
    {
        [$sql, $params] = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $class = $this->modelClass;
        return new Collection(array_map(static fn(array $row) => $class::fromRow($row), $rows));
    }

    /**
     * Returns the first matching model instance, or null when none found.
     *
     * @return object|null
     */
    public function first(): ?object
    {
        $this->limitVal = 1;
        return $this->get()->first();
    }

    /**
     * Returns the total number of matching rows.
     *
     * @return int
     */
    public function count(): int
    {
        $class = $this->modelClass;
        $table = $class::getTable();
        [$whereClause, $params] = $this->buildWhere();
        $sql  = "SELECT COUNT(*) FROM `{$table}`";
        $sql .= $whereClause !== '' ? " WHERE {$whereClause}" : '';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Builds the full SELECT SQL string and parameter list.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSelect(): array
    {
        $class = $this->modelClass;
        $table = $class::getTable();
        [$whereClause, $params] = $this->buildWhere();

        $sql  = "SELECT * FROM `{$table}`";
        $sql .= $whereClause !== '' ? " WHERE {$whereClause}" : '';

        if ($this->orderBys !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBys);
        }
        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }
        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return [$sql, $params];
    }

    /**
     * Builds the WHERE clause string and collects bound values, including
     * the soft-delete filter implied by withTrashed()/onlyTrashed() when the
     * model uses soft deletes.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(): array
    {
        $clauses = [];
        $params  = [];

        foreach ($this->wheres as $where) {
            $clauses[] = "`{$where['column']}` {$where['operator']} ?";
            $params[]  = $where['value'];
        }

        $softDeleteClause = $this->softDeleteClause();
        if ($softDeleteClause !== null) {
            $clauses[] = $softDeleteClause;
        }

        if ($clauses === []) {
            return ['', []];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Soft-delete SQL fragment implied by the model and withTrashed()/onlyTrashed(), if any.
     *
     * @return string|null
     */
    private function softDeleteClause(): ?string
    {
        $class = $this->modelClass;
        if (!$class::usesSoftDeletes()) {
            return null;
        }

        $column = $class::getDeletedAtColumn();

        if ($this->onlyTrashed) {
            return "`{$column}` IS NOT NULL";
        }

        return $this->includeTrashed ? null : "`{$column}` IS NULL";
    }
}
