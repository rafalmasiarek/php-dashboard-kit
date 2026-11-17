## Database schema

Declare tables directly in `module.php` under the `schema` key. On every boot the framework compares a hash of each table's definition against the stored hash — unchanged tables are skipped entirely. Changed or new tables are created (`CREATE TABLE IF NOT EXISTS`) and any new columns are added (`ALTER TABLE ADD COLUMN`). Columns are never dropped.

```php
// modules/notes/module.php
return [
    'title'  => 'Notes',
    'schema' => [
        'notes' => [
            'columns' => [
                'title'   => ['type' => 'string', 'null' => false],
                'body'    => ['type' => 'text'],
                'user_id' => ['type' => 'uuid', 'null' => false],
                'active'  => ['type' => 'tinyint', 'default' => 1],
            ],
            'indexes' => [
                ['columns' => ['user_id']],
            ],
        ],
    ],
    'render' => function ($req, $res, $args, $twig, $db, $container) { ... },
];
```

Every managed table automatically gets `id` (UUID `CHAR(36)`) and `created_at` (DATETIME, DEFAULT CURRENT_TIMESTAMP). Supported column types: `string`, `text`, `int`/`integer`, `bigint`, `tinyint`, `bool`/`boolean`, `decimal`, `float`, `datetime`, `date`, `json`, `uuid`.

### Tables outside modules

Tables that do not belong to any module can be declared at the top level of the config under `schema`:

```php
Dashboard::create($app, $container, [
    // ...
    'schema' => [
        'shared_settings' => [
            'columns' => [
                'key'   => ['type' => 'string', 'null' => false, 'unique' => true],
                'value' => ['type' => 'text'],
            ],
        ],
    ],
]);
```

### Table prefix

Add `table_prefix` to the config to namespace all managed tables. System tables (`_schema_state`, `_table_version`, `_query_cache`) are never prefixed.

```php
Dashboard::create($app, $container, [
    'table_prefix' => 'app_',
    // module table "notes" → "app_notes"
]);
```

In handlers that build raw SQL, use `CachingPdo::table()` to apply the prefix consistently:

```php
$stmt = $db->prepare("SELECT * FROM `" . $db->table('notes') . "` WHERE id = ?");
```

The prefix is also available as `db.table_prefix` in the container.

### Seed data

Declare default rows under the `seed` key (top-level config or per-module). Each seed table is paired with a `match` column for the existence check — rows are inserted only when no matching record exists, making seeds idempotent on every boot.

```php
'seed' => [
    'users' => [
        'match'   => 'email',        // column used for the existence check before inserting
        'with_id' => true,           // auto-generate UUID for id if absent (default)
        'rows'    => [
            [
                'email'         => 'admin@example.com',
                'role'          => 'admin',
                'active'        => 1,
                'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            ],
        ],
    ],
    'api_scopes' => [
        'match'   => 'name',
        'with_id' => false,          // table uses AUTO_INCREMENT — skip UUID injection
        'rows'    => [
            ['name' => 'notes:read',  'description' => 'Read notes'],
            ['name' => 'notes:write', 'description' => 'Manage notes'],
        ],
    ],
],
```

`created_at` is never injected — the database fills it in via `DEFAULT CURRENT_TIMESTAMP`.

## Query cache

All SELECT queries issued through `PDO::class` from the container are transparently cached — no code changes required in handlers. The cache is version-keyed: any write to a table automatically makes all cached SELECT results for that table unreachable without explicit invalidation.

Cache behaviour by statement type:

| Type | Behaviour |
|---|---|
| SELECT | Checks cache before executing; stores `fetchAll()` result on miss |
| INSERT / UPDATE / DELETE / REPLACE | Executes normally; bumps version counter for affected tables |
| System tables (`_query_cache`, `_table_version`, `_schema_state`) | Always bypass cache |

Only `fetchAll()` results are cached. `fetch()` and `fetchColumn()` always hit the database.

Default TTL is 300 seconds. The cache is stored in the `_query_cache` table and never goes stale: a write to table `T` makes all cached SELECT keys that referenced `T` unreachable — they can never be served after the write.
