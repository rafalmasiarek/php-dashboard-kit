<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Cache;

/**
 * Extracts table names and write intent from a SQL statement.
 *
 * Tokenizer-based, no external dependencies. Handles SELECT/INSERT/UPDATE/DELETE/REPLACE
 * including subqueries, JOIN qualifiers, comma-separated FROM lists, backtick-quoted
 * identifiers, and qualified column references (table.column).
 *
 * DDL statements (CREATE, ALTER, DROP, TRUNCATE, RENAME) are detected early and
 * returned as non-write with an empty table list, preventing misidentification of
 * DDL keywords inside column definitions as table names or write operations.
 *
 * @package rafalmasiarek\DashboardKit\Cache
 */
final class SqlTableExtractor
{
    /**
     * Keywords that directly introduce a table name as the next token.
     *
     * @var list<string>
     */
    private const TABLE_INTRO = ['FROM', 'JOIN', 'UPDATE', 'INTO'];

    /**
     * Keywords that mark a statement as data-modifying (write).
     *
     * @var list<string>
     */
    private const WRITE_STARTERS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    /**
     * SQL statement starters that indicate DDL — these must never be treated as writes
     * or have table names extracted, because DDL keywords inside column definitions
     * (e.g. ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY) would otherwise be misread
     * as write operations with spurious table names like 'current_timestamp' or 'primary'.
     *
     * @var list<string>
     */
    private const DDL_STARTERS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    /**
     * SQL reserved words that cannot appear as unquoted table or alias names.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER', 'OUTER', 'LEFT', 'RIGHT', 'CROSS', 'FULL',
        'ON', 'AS', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'SET', 'VALUES', 'UPDATE', 'DELETE',
        'INSERT', 'INTO', 'HAVING', 'GROUP', 'BY', 'ORDER', 'LIMIT', 'OFFSET', 'UNION', 'ALL',
        'DISTINCT', 'WITH', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'OVER', 'PARTITION', 'REPLACE',
        'USING', 'NATURAL', 'EXISTS', 'BETWEEN', 'LIKE', 'REGEXP', 'INDEX', 'KEY', 'FORCE',
        'IGNORE', 'USE', 'STRAIGHT_JOIN', 'HIGH_PRIORITY', 'LOW_PRIORITY', 'DELAYED', 'RECURSIVE',
        'TRUE', 'FALSE', 'UNKNOWN', 'ASC', 'DESC', 'RETURNING', 'EXCEPT', 'INTERSECT',
    ];

    /**
     * Extract table names and write status from a SQL statement.
     *
     * Join qualifiers (LEFT, INNER, OUTER, etc.) that appear before JOIN are skipped
     * naturally because they are not in TABLE_INTRO and are treated as no-ops by the
     * outer scanner. Subqueries are handled transparently: the scanner processes all
     * tokens linearly, so tables inside subqueries are found when their own FROM/JOIN
     * keywords are reached.
     *
     * @param  string $sql Raw SQL, may contain ? or :name placeholders.
     * @return array{tables: list<string>, is_write: bool}
     */
    public function extract(string $sql): array
    {
        $sql    = $this->stripLiterals($sql);
        $sql    = $this->stripComments($sql);
        $tokens = $this->tokenize($sql);

        // Detect DDL statements early: scan for the first keyword token.
        foreach ($tokens as $token) {
            $up = strtoupper($token);
            if ($up === '(' || $up === ')' || $up === ',') {
                continue;
            }
            if (in_array($up, self::DDL_STARTERS, true)) {
                return ['tables' => [], 'is_write' => false];
            }
            break; // first non-punctuation token is not DDL
        }

        $tables  = [];
        $isWrite = false;
        $n       = count($tokens);
        $i       = 0;

        while ($i < $n) {
            $upper = strtoupper($tokens[$i]);

            if (in_array($upper, self::WRITE_STARTERS, true)) {
                $isWrite = true;
            }

            if (in_array($upper, self::TABLE_INTRO, true)) {
                $i++; // advance past the intro keyword

                // Comma loop: collect one or more table names (FROM t1, t2, t3).
                while ($i < $n) {
                    if ($tokens[$i] === '(') {
                        // Subquery or function — stop; tables inside will be found
                        // when the linear scan reaches their own FROM/JOIN keywords.
                        break;
                    }

                    $name = $this->unquote($tokens[$i]);

                    if (!$this->isIdentifier($name)) {
                        break;
                    }

                    $tables[] = strtolower($name);
                    $i++;

                    // Skip optional alias: AS <alias> or bare <alias>.
                    if ($i < $n && strtoupper($tokens[$i]) === 'AS') {
                        $i += 2; // skip AS + alias token
                    } elseif (
                        $i < $n
                        && $tokens[$i] !== '('
                        && $tokens[$i] !== ','
                        && $this->isIdentifier($this->unquote($tokens[$i]))
                    ) {
                        $i++; // skip implicit alias
                    }

                    // Continue on comma, stop otherwise.
                    if ($i < $n && $tokens[$i] === ',') {
                        $i++;
                    } else {
                        break;
                    }
                }
            } else {
                $i++;
            }
        }

        return [
            'tables'   => array_values(array_unique($tables)),
            'is_write' => $isWrite,
        ];
    }

    /**
     * Replace single- and double-quoted string literals with empty placeholders.
     *
     * Prevents SQL keywords inside string values from being misread as table-intro
     * keywords (e.g. a value like "FROM users" inside a LIKE clause).
     *
     * @param  string $sql
     * @return string
     */
    private function stripLiterals(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $sql) ?? $sql;
        return $sql;
    }

    /**
     * Remove SQL line comments (--) and block comments (/* … * /).
     *
     * @param  string $sql
     * @return string
     */
    private function stripComments(string $sql): string
    {
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        return $sql;
    }

    /**
     * Split the SQL into meaningful tokens.
     *
     * Matches (in priority order):
     *   1. Backtick-quoted identifiers: `table_name`
     *   2. Qualified references (col or tbl.col) — dot notation kept together so
     *      that `users.id` is one token and rejected by isIdentifier (dot present).
     *   3. Parentheses and commas — structural delimiters.
     *
     * @param  string     $sql
     * @return list<string>
     */
    private function tokenize(string $sql): array
    {
        preg_match_all(
            '/`[^`]*`|[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\.[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)?|\(|\)|,/',
            $sql,
            $matches,
        );

        return $matches[0];
    }

    /**
     * Strip surrounding backticks from a token.
     *
     * @param  string $token
     * @return string
     */
    private function unquote(string $token): string
    {
        return (str_starts_with($token, '`') && str_ends_with($token, '`'))
            ? substr($token, 1, -1)
            : $token;
    }

    /**
     * Return true when the (already unquoted) token is a valid table or alias name.
     *
     * Rejects SQL reserved words and qualified references (tokens containing a dot,
     * which are column references like users.id).
     *
     * @param  string $name Unquoted token.
     * @return bool
     */
    private function isIdentifier(string $name): bool
    {
        return !str_contains($name, '.')
            && (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)
            && !in_array(strtoupper($name), self::RESERVED, true);
    }
}
