<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Model;

use DateTimeImmutable;
use PDO;

/**
 * Abstract base class for all application database models.
 *
 * Wire the PDO connection once at boot time via setConnectionResolver() —
 * typically called inside Dashboard::create() so models automatically use
 * CachingPdo and benefit from transparent SELECT caching.
 *
 * Each concrete model must declare at minimum:
 *   protected static string $table = 'my_table';
 *
 * Type casting is declared via:
 *   protected static array $casts = ['active' => 'bool', 'created_at' => 'datetime'];
 *
 * Supported cast types: int, float, bool, string, datetime (→ DateTimeImmutable),
 * json, array (both decode JSON to associative array).
 *
 * @package rafalmasiarek\DashboardKit\Model
 */
abstract class Model
{
    /**
     * Database table name. Must be overridden in every concrete model.
     *
     * @var string
     */
    protected static string $table;

    /**
     * Primary key column name.
     *
     * @var string
     */
    protected static string $primaryKey = 'id';

    /**
     * Column type casts applied when reading attribute values.
     *
     * @var array<string, 'int'|'float'|'bool'|'string'|'datetime'|'json'|'array'>
     */
    protected static array $casts = [];

    /**
     * Lazy connection factory. Called once; result is cached in $resolved.
     *
     * @var \Closure|null
     */
    private static ?\Closure $connectionResolver = null;

    /**
     * Resolved PDO instance cached after the first db() call.
     *
     * @var PDO|null
     */
    private static ?PDO $resolved = null;

    /**
     * Raw attribute values as returned by the database driver.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Whether this instance corresponds to a persisted database row.
     *
     * @var bool
     */
    protected bool $exists = false;

    /**
     * Registers the PDO factory used by all model classes.
     *
     * Pass a closure rather than the PDO instance directly so that the
     * connection is not resolved until the first model query — important
     * when Dashboard::create() wires models before the container is fully warm.
     *
     * @param  \Closure(): PDO $resolver Factory that returns a PDO connection.
     * @return void
     */
    public static function setConnectionResolver(\Closure $resolver): void
    {
        static::$connectionResolver = $resolver;
        static::$resolved           = null;
    }

    /**
     * Returns the logical table name declared by the concrete model.
     *
     * Used by QueryBuilder to build SQL without accessing protected statics directly.
     *
     * @return string
     */
    public static function getTable(): string
    {
        return static::$table;
    }

    /**
     * Returns the active PDO connection, resolving it on the first call.
     *
     * @return PDO
     *
     * @throws \RuntimeException When no resolver has been registered.
     */
    protected static function db(): PDO
    {
        if (static::$resolved === null) {
            if (static::$connectionResolver === null) {
                throw new \RuntimeException(
                    'No database connection configured. Call Model::setConnectionResolver() before querying models.'
                );
            }
            static::$resolved = (static::$connectionResolver)();
        }

        return static::$resolved;
    }

    /**
     * Creates a model instance from a raw database row without triggering a query.
     *
     * @param  array<string, mixed> $row Column-value map from PDO::FETCH_ASSOC.
     * @return static
     */
    public static function fromRow(array $row): static
    {
        $instance             = new static();
        $instance->attributes = $row;
        $instance->exists     = true;
        return $instance;
    }

    /**
     * Finds a single model by primary key.
     *
     * @param  int|string $id Primary key value.
     * @return static|null     Null when no matching row exists.
     */
    public static function find(int|string $id): ?static
    {
        $table = static::$table;
        $pk    = static::$primaryKey;
        $stmt  = static::db()->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? static::fromRow($row) : null;
    }

    /**
     * Returns all rows in the table as a Collection of model instances.
     *
     * @return Collection
     */
    public static function all(): Collection
    {
        $table = static::$table;
        $stmt  = static::db()->query("SELECT * FROM `{$table}`");
        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return new Collection(array_map(static fn(array $r) => static::fromRow($r), $rows));
    }

    /**
     * Starts a fluent WHERE chain for the model's table.
     *
     * When called with two arguments, '=' is assumed as the operator:
     *   Model::where('active', 1)        → WHERE `active` = ?
     *   Model::where('role', '!=', 'user') → WHERE `role` != ?
     *
     * @param  string $column          Column name (not user input).
     * @param  mixed  $operatorOrValue Operator string when $value is provided; bound value otherwise.
     * @param  mixed  $value           Bound value when an explicit operator is given.
     * @return QueryBuilder
     */
    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        [$operator, $boundValue] = $value !== null
            ? [(string) $operatorOrValue, $value]
            : ['=', $operatorOrValue];

        return (new QueryBuilder(static::class, static::db()))->where($column, $operator, $boundValue);
    }

    /**
     * Inserts a new row and returns the resulting model instance.
     *
     * @param  array<string, mixed> $attributes Column-value pairs to insert.
     * @return static
     */
    public static function create(array $attributes): static
    {
        $instance = new static();
        $instance->attributes = $attributes;
        $instance->save();
        return $instance;
    }

    /**
     * Reads an attribute value with type casting applied.
     *
     * @param  string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Sets an attribute value.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Returns true when the attribute exists in the current row (even if null).
     *
     * @param  string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Returns the cast value for a given attribute key.
     *
     * @param  string $key
     * @return mixed  Null when the key does not exist in attributes.
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }
        return $this->cast($key, $this->attributes[$key]);
    }

    /**
     * Stores a raw value for the given attribute key.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Sets multiple attributes at once and returns $this for chaining.
     *
     * @param  array<string, mixed> $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * Returns all attributes as an associative array with casts applied.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->cast($key, $value);
        }
        return $result;
    }

    /**
     * Persists the model to the database.
     *
     * Issues INSERT when $exists is false; UPDATE when $exists is true.
     * On successful INSERT, sets $exists = true and stores the last-insert-id
     * when no primary-key value was provided in attributes.
     *
     * @return bool True on success.
     */
    public function save(): bool
    {
        $table = static::$table;
        $pk    = static::$primaryKey;

        if ($this->exists) {
            $data = array_filter(
                $this->attributes,
                static fn(string $k) => $k !== $pk,
                ARRAY_FILTER_USE_KEY,
            );
            $sets = implode(', ', array_map(static fn(string $k) => "`{$k}` = ?", array_keys($data)));
            $stmt = static::db()->prepare("UPDATE `{$table}` SET {$sets} WHERE `{$pk}` = ?");
            return $stmt->execute([...array_values($data), $this->attributes[$pk]]);
        }

        $columns      = implode(', ', array_map(static fn(string $k) => "`{$k}`", array_keys($this->attributes)));
        $placeholders = implode(', ', array_fill(0, count($this->attributes), '?'));
        $stmt         = static::db()->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})");
        $result       = $stmt->execute(array_values($this->attributes));

        if ($result) {
            $this->exists = true;
            if (!isset($this->attributes[$pk])) {
                $this->attributes[$pk] = static::db()->lastInsertId();
            }
        }

        return $result;
    }

    /**
     * Deletes the row from the database and marks the instance as non-persisted.
     *
     * @return bool False when the instance has not been persisted yet.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $table  = static::$table;
        $pk     = static::$primaryKey;
        $stmt   = static::db()->prepare("DELETE FROM `{$table}` WHERE `{$pk}` = ?");
        $result = $stmt->execute([$this->attributes[$pk]]);

        if ($result) {
            $this->exists = false;
        }

        return $result;
    }

    /**
     * Applies the declared cast for a column to its raw DB value.
     *
     * @param  string $key   Column name.
     * @param  mixed  $value Raw value from PDO.
     * @return mixed         Typed value, or the raw value when no cast is declared.
     */
    protected function cast(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (static::$casts[$key] ?? null) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => (bool) $value,
            'string' => (string) $value,
            'datetime' => new DateTimeImmutable((string) $value),
            'json', 'array' => json_decode((string) $value, true),
            default  => $value,
        };
    }

    /**
     * Loads all related models where $foreignKey on the related table equals this model's $localKey.
     *
     * @param  class-string<Model> $related    Fully-qualified related model class.
     * @param  string              $foreignKey Column on the related table pointing back to this model.
     * @param  string              $localKey   Column on this model used as the local identifier. Defaults to primary key.
     * @return Collection
     */
    protected function hasMany(string $related, string $foreignKey, string $localKey = ''): Collection
    {
        $localKey   = $localKey !== '' ? $localKey : static::$primaryKey;
        $localValue = $this->attributes[$localKey] ?? null;
        $table      = $related::getTable();
        $stmt       = static::db()->prepare("SELECT * FROM `{$table}` WHERE `{$foreignKey}` = ?");
        $stmt->execute([$localValue]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return new Collection(array_map(static fn(array $r) => $related::fromRow($r), $rows));
    }

    /**
     * Loads the single related model whose $ownerKey matches this model's $foreignKey value.
     *
     * @param  class-string<Model> $related    Fully-qualified related model class.
     * @param  string              $foreignKey Column on this model holding the foreign key value.
     * @param  string              $ownerKey   Column on the related table to match against. Defaults to related primary key.
     * @return object|null
     */
    protected function belongsTo(string $related, string $foreignKey, string $ownerKey = ''): ?object
    {
        $ownerKey     = $ownerKey !== '' ? $ownerKey : $related::$primaryKey;
        $foreignValue = $this->attributes[$foreignKey] ?? null;

        if ($foreignValue === null) {
            return null;
        }

        $table = $related::getTable();
        $stmt  = static::db()->prepare("SELECT * FROM `{$table}` WHERE `{$ownerKey}` = ? LIMIT 1");
        $stmt->execute([$foreignValue]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $related::fromRow($row) : null;
    }
}
