# Logging

Dashboard Kit uses [Monolog](https://github.com/Seldaek/monolog) with three built-in channels. Every log line is written in a structured key-value format designed to be consumed by log shippers.

## Channels

| Channel | File | Default level | Retention | Purpose |
|---------|------|---------------|-----------|---------|
| `app` | `app.log` | `info` | 30 days | General application events, warnings, framework errors |
| `audit` | `audit.log` | `info` | 90 days | User and admin action trail (login, password change, role changes…) |
| `error` | `error.log` | `error` | 30 days | 5xx errors only — quick triage without noise |

The `app` channel cascades: any `ERROR`+ message is written to both `app.log` and `error.log`.

## File locations

### Base directory

All log files are written under a single base directory. The default is `{rootDir}/storage/logs`, where `rootDir` is the first argument to `Dashboard::create()`.

Override it with the `logs_dir` config key:

```php
Dashboard::create(__DIR__ . '/../', [
    'logs_dir' => '/var/log/myapp',   // absolute path
]);
```

The directory is created automatically if it does not exist, provided the web server user has write access to the parent. For Docker volume mounts create the directory on the host before starting the container.

### Per-channel path

Each channel has a `path` option. A path **without** a leading `/` is resolved relative to `logs_dir`. A path **with** a leading `/` is used as-is and ignores `logs_dir`.

```php
Dashboard::create(__DIR__ . '/../', [
    'logs_dir' => '/var/log/myapp',
    'logging'  => [
        'app'   => ['path' => 'app.log'],               // → /var/log/myapp/app.log
        'audit' => ['path' => 'audit.log'],              // → /var/log/myapp/audit.log
        'error' => ['path' => '/data/critical/error.log'], // absolute — logs_dir ignored
    ],
])
```

### Defaults at a glance

| Channel | Default path (relative to `logs_dir`) |
|---------|---------------------------------------|
| `app`   | `app.log` |
| `audit` | `audit.log` |
| `error` | `error.log` |

Monolog appends the current date automatically: `app.log` → `app-2026-07-04.log`.

---

## Configuration

Pass a `logging` key to `Dashboard::create()`. All fields are optional — omitted values use the defaults above.

```php
Dashboard::create(__DIR__ . '/../', [
    'logging' => [
        'app' => [
            'path'  => 'storage/logs/app.log',   // relative to logs_dir, or absolute
            'level' => 'debug',                   // PSR-3 level string
            'days'  => 30,                        // daily rotation, N files kept
        ],
        'audit' => [
            'days' => 90,
        ],
        'error' => [
            'path' => 'storage/logs/error.log',
        ],
    ],
    'logs_dir' => __DIR__ . '/../storage/logs',  // base dir for relative paths (default)
])
```

A path without a leading `/` is resolved relative to `logs_dir`.

### setLogLevel()

Overrides the minimum level of the `app` channel at runtime. Useful to switch to `debug` in development without changing the config array.

```php
Dashboard::create(...)
    ->setLogLevel(\Psr\Log\LogLevel::DEBUG)
    ->run();
```

This does not affect the `audit` or `error` channels.

## Log line format

```
[2026-07-04 14:28:45] [level=info] [channel=audit] dashboard.login user_id=7 email=user@example.com ip=192.168.1.1
[2026-07-04 14:29:10] [level=warning] [channel=app] Not found. exception="Slim\Exception\HttpNotFoundException: Not found." file=...
[2026-07-04 14:30:01] [level=error] [channel=app] DB query failed. exception="PDOException: SQLSTATE[42S02]" file=...
```

Fields:
- `[level=…]` — PSR-3 level in lowercase
- `[channel=…]` — channel name
- First word after brackets — event name (used as message when no explicit `message` key)
- Remaining `key=value` pairs — context passed to the logger

Values containing spaces or quotes are double-quoted. Arrays and objects are JSON-encoded.

## log_ship.conf integration

The format is compatible with `log_ship.sh`'s `kv` parser. Recommended entries:

```bash
LOG_FILES=(
  "/var/www/html/storage/logs/audit-$(date +%Y-%m-%d).log | myapp-audit | kv | yourdomain.com"
  "/var/www/html/storage/logs/app-$(date +%Y-%m-%d).log   | myapp-log   | kv | yourdomain.com"
  "/var/www/html/storage/logs/error-$(date +%Y-%m-%d).log | myapp-error | kv | yourdomain.com"
)

PARSERS=(
  "kv | level=level | msg=message"
)
```

Monolog appends the date to filenames automatically: `app.log` becomes `app-2026-07-04.log`.

## Logging from your code

### In a module handler

The PSR-11 container is available as the last argument of every handler. Resolve `LoggerInterface` to write to the `app` channel.

```php
use Psr\Log\LoggerInterface;

'routes' => [
    'POST /' => function ($req, $res, $args, $view, $db, $container): Response {
        $logger = $container->get(LoggerInterface::class);

        $logger->info('order.created', ['order_id' => 123, 'total' => 49.99]);
        $logger->warning('stock.low',  ['product_id' => 7, 'remaining' => 2]);
        $logger->error('payment.failed', ['reason' => 'card_declined']);

        return $res->withHeader('Location', '/orders')->withStatus(302);
    },
],
```

### In a hook

Capture the container before calling `run()`.

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [...]);
$logger    = $dashboard->getContainer()->get(LoggerInterface::class);

$dashboard->on('register', function (\AuthKit\User $user) use ($logger) {
    $logger->info('user.registered', ['email' => $user->getEmail()]);
    // send welcome email, notify Slack, etc.
});

$dashboard->run();
```

### Accessing a specific channel

```php
// audit channel — use for security-relevant events
$container->get('logger.audit')->info('payment.refunded', [
    'order_id'   => 42,
    'refund_amt' => 19.99,
    'by_user_id' => 1,
]);

// app channel (same as LoggerInterface)
$container->get('logger.app')->debug('cache.miss', ['key' => 'products.list']);
```

## Adding a custom channel

Define it in the `logging` config — it is automatically registered as `logger.{name}` in the container.

```php
Dashboard::create(__DIR__ . '/../', [
    'logging' => [
        'payments' => ['path' => 'payments.log', 'level' => 'debug', 'days' => 60],
        'jobs'     => ['path' => 'jobs.log',     'level' => 'info',  'days' => 14],
    ],
])
```

```php
// In a module or hook:
$container->get('logger.payments')->info('charge.succeeded', ['amount' => 99.00]);
$container->get('logger.jobs')->warning('job.retried', ['job' => 'SendInvoice', 'attempt' => 2]);
```

## Sending logs to an external service

Override any channel in the container before `run()`. The key is `logger.{channel}` — set it to any PSR-3 logger.

### Logstash (TCP)

```php
use Monolog\Handler\SocketHandler;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Logger;

$dashboard->getContainer()->set('logger.app', function () {
    $handler = new SocketHandler('tcp://logstash.internal:5044');
    $handler->setFormatter(new LogstashFormatter('myapp'));

    return new Logger('app', [$handler]);
});
```

### Graylog (GELF UDP)

```php
use Monolog\Handler\GelfHandler;
use Gelf\Publisher;
use Gelf\Transport\UdpTransport;

$dashboard->getContainer()->set('logger.app', function () {
    $handler = new GelfHandler(
        new Publisher(new UdpTransport('graylog.internal', 12201))
    );
    return new Logger('app', [$handler]);
});
```

Requires `graylog2/gelf-php`: `composer require graylog2/gelf-php`.

### Elasticsearch / OpenSearch

```php
use Monolog\Handler\ElasticsearchHandler;
use Elastic\Elasticsearch\ClientBuilder;

$dashboard->getContainer()->set('logger.app', function () {
    $client  = ClientBuilder::create()->setHosts(['elasticsearch:9200'])->build();
    $handler = new ElasticsearchHandler($client, ['index' => 'myapp-logs']);
    return new Logger('app', [$handler]);
});
```

### File + remote simultaneously

Keep files as a local backup while also shipping to an external service. Push multiple handlers onto one logger — both receive every message.

```php
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Formatter\LogstashFormatter;
use RafalMasiarek\DashboardKit\Log\KvLineFormatter;

$dashboard->getContainer()->set('logger.app', function () {
    $fileHandler = new RotatingFileHandler('/var/www/html/storage/logs/app.log', 30);
    $fileHandler->setFormatter(new KvLineFormatter());

    $remoteHandler = new SocketHandler('tcp://logstash.internal:5044');
    $remoteHandler->setFormatter(new LogstashFormatter('myapp'));

    return new Logger('app', [$fileHandler, $remoteHandler]);
});
```

The same pattern works for `logger.audit` — ship your audit trail to a dedicated Logstash index or a separate Elasticsearch data stream.

## Replacing the logger entirely

To replace the default `app` channel binding (`LoggerInterface`) with any PSR-3 compatible logger:

```php
$dashboard->getContainer()->set(
    \Psr\Log\LoggerInterface::class,
    fn() => new \Monolog\Logger('myapp', [$myHandler])
);
```

This overrides the `app` channel only. `logger.audit` and `logger.error` are unaffected.

## PSR-3 log levels

From most to least verbose:

| Constant | String | When to use |
|----------|--------|-------------|
| `LogLevel::DEBUG` | `debug` | Fine-grained diagnostic detail |
| `LogLevel::INFO` | `info` | Normal events worth recording |
| `LogLevel::NOTICE` | `notice` | Normal but significant events |
| `LogLevel::WARNING` | `warning` | Unexpected situations that are not errors |
| `LogLevel::ERROR` | `error` | Runtime errors, failed operations |
| `LogLevel::CRITICAL` | `critical` | Component unavailable, data loss risk |
| `LogLevel::ALERT` | `alert` | Action required immediately |
| `LogLevel::EMERGENCY` | `emergency` | System unusable |
