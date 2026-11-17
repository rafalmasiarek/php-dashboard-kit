## Requirements

- PHP 8.2+
- MySQL 8 or SQLite
- Composer

## Installation

```bash
composer require rafalmasiarek/dashboard-kit
```

## Quick start

```php
use DI\ContainerBuilder;
use rafalmasiarek\DashboardKit\Dashboard;
use Slim\Factory\AppFactory;

$container = (new ContainerBuilder())->build();
AppFactory::setContainer($container);
$app = AppFactory::create();

Dashboard::create($app, $container, [
    'db' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'dbname'   => 'myapp',
        'user'     => 'root',
        'password' => 'secret',
    ],
    'modules_path'       => __DIR__ . '/modules',
    'admin_modules_path' => __DIR__ . '/admin_modules',
    'storage_path'       => __DIR__ . '/storage',
    'templates_path'     => __DIR__ . '/templates',
    'app' => [
        'env'  => 'production',
        'name' => 'My App',
    ],
]);

$app->run();
```

## Module structure

Each module is a directory containing `module.php`:

```php
// modules/notes/module.php
return [
    'title'  => 'Notes',
    'icon'   => '📝',
    'render' => function ($req, $res, $args, $twig, $db, $container) {
        return $twig->render($res, 'notes/index.twig', []);
    },
];
```

Modules appear in the navbar automatically. Admin modules (under `admin_modules_path`) appear in the `/admin` panel.
