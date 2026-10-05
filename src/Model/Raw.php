<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Model;

/**
 * Wraps a raw SQL expression (e.g. 'NOW()') so QueryBuilder emits it
 * unescaped in a WHERE clause instead of binding it as a parameter.
 *
 * @package rafalmasiarek\DashboardKit\Model
 */
final class Raw
{
    /**
     * @param string $sql Raw SQL fragment, emitted verbatim.
     */
    public function __construct(public readonly string $sql)
    {
    }
}
