<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Model;

/**
 * Typed iterable wrapper for a list of Model instances or arbitrary values.
 *
 * Returned by Model::all(), Model::where()->get(), and relation methods.
 * Implements standard PHP collection interfaces so it works in foreach,
 * count(), and array-access contexts without casting to a plain array.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 * @implements \ArrayAccess<int, T>
 *
 * @package rafalmasiarek\DashboardKit\Model
 */
final class Collection implements \Countable, \IteratorAggregate, \ArrayAccess
{
    /**
     * @param list<T> $items Items in this collection.
     */
    public function __construct(private array $items = []) {}

    /**
     * Returns the number of items in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Returns an iterator over the items.
     *
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @param int $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @param  int $offset
     * @return T
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * @param  int|null $offset
     * @param  T        $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * @param int $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Returns the first item, or null when the collection is empty.
     *
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * Returns the last item, or null when the collection is empty.
     *
     * @return T|null
     */
    public function last(): mixed
    {
        return $this->items !== [] ? $this->items[count($this->items) - 1] : null;
    }

    /**
     * Returns true when the collection contains no items.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Converts every Model item to an associative array; passes other values through.
     *
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return array_map(
            static fn($item) => $item instanceof Model ? $item->toArray() : $item,
            $this->items,
        );
    }

    /**
     * Returns a new collection containing only items for which the callback returns true.
     *
     * The resulting list is re-indexed starting from 0.
     *
     * @param  callable(T): bool $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        return new static(array_values(array_filter($this->items, $callback)));
    }

    /**
     * Applies the callback to every item and returns a new collection of the results.
     *
     * @param  callable(T): mixed $callback
     * @return static
     */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Extracts a single attribute from each item.
     *
     * Calls ->get($key) on Model instances; reads $item[$key] from arrays.
     *
     * @param  string $key Attribute or array key to extract.
     * @return list<mixed>
     */
    public function pluck(string $key): array
    {
        return array_map(
            static fn($item) => $item instanceof Model ? $item->get($key) : ($item[$key] ?? null),
            $this->items,
        );
    }
}
