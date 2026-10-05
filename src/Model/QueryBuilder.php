<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Model;

use DateTimeImmutable;
use PDO;

/**
 * Fluent SELECT query builder for a model class or an ad-hoc table.
 *
 * Created by Model::where()/Model::on() and returned for method chaining.
 * Column and table names are interpolated directly into SQL — callers must
 * not pass user-supplied identifiers. Values are always bound as
 * prepared-statement parameters, except Raw (emitted verbatim).
 *
 * @package rafalmasiarek\DashboardKit\Model
 */
final class QueryBuilder
{
    /**
     * WHERE conditions accumulated via where()/whereIn() calls.
     *
     * @var list<array{column: string, operator: string, value: mixed}|array{column: string, in: list<mixed>, not: bool}>
     */
    private array $wheres = [];

    /**
     * JOIN clauses accumulated via join()/leftJoin().
     *
     * @var list<string>
     */
    private array $joins = [];

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
     * SELECT column list. Defaults to '*'. Set via select() — e.g. for a
     * join() where some joined table's columns should be left out.
     *
     * @var list<string>
     */
    private array $selectColumns = ['*'];

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
     * @param string|null $modelClass     Fully-qualified model class, or null for an ad-hoc
     *                                     table (Model::on()) — rows are returned as plain
     *                                     arrays instead of model instances when null.
     * @param string      $table          Table name.
     * @param PDO         $pdo            Active database connection.
     * @param bool        $softDeletes    Whether to filter deleted_at automatically.
     * @param string      $deletedAtColumn Column soft-deletes are written to.
     */
    public function __construct(
        private readonly ?string $modelClass,
        private readonly string $table,
        private readonly PDO $pdo,
        private readonly bool $softDeletes = false,
        private readonly string $deletedAtColumn = 'deleted_at',
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
     * A null value renders IS NULL / IS NOT NULL instead of binding a
     * parameter. A Raw value is emitted verbatim instead of being bound:
     *   ->where('expires_at', '>', new Raw('NOW()'))
     *
     * $column is backtick-wrapped as one identifier — do not pass a
     * qualified 'table.column' name here (use select()/join() for those).
     *
     * @param  string $column          Column name (trusted, not user input).
     * @param  mixed  $operatorOrValue Operator string when $value is given; bound value otherwise.
     * @param  mixed  $value           Bound value, null, or Raw when an explicit operator is given.
     * @return static
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $boundValue] = func_num_args() >= 3
            ? [(string) $operatorOrValue, $value]
            : ['=', $operatorOrValue];

        $this->wheres[] = ['column' => $column, 'operator' => $operator, 'value' => $boundValue];
        return $this;
    }

    /**
     * Adds a WHERE column IN (...) condition.
     *
     * @param  string      $column Column name (trusted, not user input).
     * @param  list<mixed> $values Bound values. An empty list matches no rows.
     * @return static
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['column' => $column, 'in' => $values, 'not' => false];
        return $this;
    }

    /**
     * Adds a WHERE column NOT IN (...) condition.
     *
     * @param  string      $column Column name (trusted, not user input).
     * @param  list<mixed> $values Bound values. An empty list matches every row.
     * @return static
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['column' => $column, 'in' => $values, 'not' => true];
        return $this;
    }

    /**
     * Sets the SELECT column list, replacing the default '*'. Columns are
     * emitted verbatim (trusted, not user input) — qualify them yourself
     * for a join, e.g. select('user_tokens.token', 'users.email').
     *
     * @param  string ...$columns
     * @return static
     */
    public function select(string ...$columns): static
    {
        $this->selectColumns = $columns !== [] ? $columns : ['*'];
        return $this;
    }

    /**
     * Adds an INNER JOIN clause.
     *
     * @param  string $table    Table to join (trusted, not user input).
     * @param  string $first    Column on the already-selected table(s).
     * @param  string $operator Comparison operator, usually '='.
     * @param  string $second   Column on $table.
     * @return static
     */
    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "INNER JOIN `{$table}` ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Adds a LEFT JOIN clause.
     *
     * @param  string $table    Table to join (trusted, not user input).
     * @param  string $first    Column on the already-selected table(s).
     * @param  string $operator Comparison operator, usually '='.
     * @param  string $second   Column on $table.
     * @return static
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "LEFT JOIN `{$table}` ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Inserts a row into this builder's table. The only way to insert when
     * built from Model::on() — there is no model class to construct and save().
     * Ignores any accumulated WHERE/JOIN/etc.; those only apply to get()/
     * first()/count()/update()/delete().
     *
     * @param  array<string, mixed> $attributes
     * @return string Last-insert-id, or '' if the driver reports none.
     */
    public function insert(array $attributes): string
    {
        $columns      = implode(', ', array_map(static fn(string $k) => "`{$k}`", array_keys($attributes)));
        $placeholders = implode(', ', array_fill(0, count($attributes), '?'));
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($attributes));
        return $this->pdo->lastInsertId();
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
     * Executes the SELECT. Returns model instances when built from a model
     * class, or plain associative arrays for an ad-hoc table (Model::on()).
     *
     * @return Collection
     */
    public function get(): Collection
    {
        [$sql, $params] = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($this->modelClass === null) {
            return new Collection($rows);
        }

        $class = $this->modelClass;
        return new Collection(array_map(static fn(array $row) => $class::fromRow($row), $rows));
    }

    /**
     * Returns the first matching row, or null when none found.
     *
     * @return object|array<string, mixed>|null
     */
    public function first(): object|array|null
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
        [$whereClause, $params] = $this->buildWhere();
        $sql  = "SELECT COUNT(*) FROM `{$this->table}`" . $this->joinSql();
        $sql .= $whereClause !== '' ? " WHERE {$whereClause}" : '';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every matching row in one statement. Soft-deletes (bulk UPDATE
     * of deletedAtColumn) when the model uses soft deletes; otherwise a hard
     * DELETE. Use forceDelete() to always hard-delete regardless.
     *
     * @return int Number of rows affected.
     */
    public function delete(): int
    {
        if ($this->softDeletes && !$this->onlyTrashed) {
            return $this->update([$this->deletedAtColumn => (new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        }

        return $this->forceDelete();
    }

    /**
     * Deletes every matching row outright in one statement, bypassing soft deletes.
     *
     * @return int Number of rows affected.
     */
    public function forceDelete(): int
    {
        [$whereClause, $params] = $this->buildWhere();
        $sql  = "DELETE FROM `{$this->table}`";
        $sql .= $whereClause !== '' ? " WHERE {$whereClause}" : '';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Updates every matching row with the given column values in one statement.
     * Does not auto-manage timestamps — include updatedAtColumn in $attributes
     * if it should be refreshed.
     *
     * @param  array<string, mixed> $attributes
     * @return int Number of rows affected.
     */
    public function update(array $attributes): int
    {
        [$whereClause, $whereParams] = $this->buildWhere();
        $sets = implode(', ', array_map(static fn(string $k) => "`{$k}` = ?", array_keys($attributes)));
        $sql  = "UPDATE `{$this->table}` SET {$sets}";
        $sql .= $whereClause !== '' ? " WHERE {$whereClause}" : '';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...array_values($attributes), ...$whereParams]);
        return $stmt->rowCount();
    }

    /**
     * Builds the full SELECT SQL string and parameter list.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSelect(): array
    {
        [$whereClause, $params] = $this->buildWhere();

        $columns = implode(', ', $this->selectColumns);
        $sql     = "SELECT {$columns} FROM `{$this->table}`" . $this->joinSql();
        $sql    .= $whereClause !== '' ? " WHERE {$whereClause}" : '';

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
     * Joins accumulated join()/leftJoin() clauses into one SQL fragment.
     *
     * @return string
     */
    private function joinSql(): string
    {
        return $this->joins === [] ? '' : ' ' . implode(' ', $this->joins);
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
            if (isset($where['in'])) {
                /** @var list<mixed> $values */
                $values = $where['in'];
                if ($values === []) {
                    $clauses[] = $where['not'] ? '1 = 1' : '1 = 0';
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $keyword      = $where['not'] ? 'NOT IN' : 'IN';
                $clauses[]    = "`{$where['column']}` {$keyword} ({$placeholders})";
                foreach ($values as $v) {
                    $params[] = $v;
                }
                continue;
            }

            $clauses[] = $this->renderCondition((string) $where['column'], (string) $where['operator'], $where['value'], $params);
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
     * Renders one WHERE condition, appending any bound parameter to $params by reference.
     *
     * @param  string      $column
     * @param  string      $operator
     * @param  mixed       $value
     * @param  list<mixed> $params
     * @return string
     */
    private function renderCondition(string $column, string $operator, mixed $value, array &$params): string
    {
        if ($value instanceof Raw) {
            return "`{$column}` {$operator} {$value->sql}";
        }

        if ($value === null) {
            return $operator === '!=' || $operator === '<>'
                ? "`{$column}` IS NOT NULL"
                : "`{$column}` IS NULL";
        }

        $params[] = $value;
        return "`{$column}` {$operator} ?";
    }

    /**
     * Soft-delete SQL fragment implied by the model and withTrashed()/onlyTrashed(), if any.
     *
     * @return string|null
     */
    private function softDeleteClause(): ?string
    {
        if (!$this->softDeletes) {
            return null;
        }

        if ($this->onlyTrashed) {
            return "`{$this->deletedAtColumn}` IS NOT NULL";
        }

        return $this->includeTrashed ? null : "`{$this->deletedAtColumn}` IS NULL";
    }
}
