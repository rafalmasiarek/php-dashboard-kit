<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Schema;

use AuthKit\Extension\SchemaProviderInterface;

/**
 * Declares ALTER TABLE statements for project-configured custom user fields.
 *
 * Pass to Auth::createSchema() so that extra profile columns are created
 * alongside the core schema:
 *
 *   $auth->createSchema(..., new UserFieldsSchemaProvider($userFields));
 *
 * @package rafalmasiarek\DashboardKit\Schema
 */
final class UserFieldsSchemaProvider implements SchemaProviderInterface
{
    /**
     * @param array<string, array{label: string, type: string, max: int, required: bool}> $fields
     */
    public function __construct(private readonly array $fields)
    {
    }

    /**
     * @param  string        $driver PDO driver name.
     * @return list<string>
     */
    public function additionalSchema(string $driver): array
    {
        $statements = [];

        foreach ($this->fields as $name => $field) {
            $colDef = $this->sqlType($field, $driver);

            $statements[] = "ALTER TABLE users ADD COLUMN `{$name}` {$colDef}";
        }

        return $statements;
    }

    /**
     * Maps a user-field definition to a SQL column type for the given driver.
     *
     * @param  array{label: string, type: string, max: int, required: bool} $field
     * @param  string                                                        $driver
     * @return string
     */
    private function sqlType(array $field, string $driver): string
    {
        return match ($field['type']) {
            'date'     => 'DATE NULL DEFAULT NULL',
            'textarea' => 'TEXT NULL',
            default    => 'VARCHAR(' . $field['max'] . ') NULL DEFAULT NULL',
        };
    }
}
