<?php

namespace rafalmasiarek\DashboardKit;

use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;
use rafalmasiarek\DashboardKit\Extension\ActiveUserExtension;
use rafalmasiarek\DashboardKit\Extension\FormSlotRegistry;
use rafalmasiarek\DashboardKit\Extension\SettingsSectionRegistry;
use rafalmasiarek\DashboardKit\Extension\SuspendedUserExtension;
use rafalmasiarek\DashboardKit\Extension\UserActionRegistry;
use rafalmasiarek\DashboardKit\Http\RequestTimingMiddleware;
use rafalmasiarek\DashboardKit\Http\SafeErrorHandler;
use rafalmasiarek\DashboardKit\Security\SecretRegistry;
use rafalmasiarek\DashboardKit\Cache\CachingPdo;
use rafalmasiarek\DashboardKit\Cache\Driver\PdoQueryCacheDriver;
use rafalmasiarek\DashboardKit\Cache\QueryCacheDriverInterface;
use rafalmasiarek\DashboardKit\Schema\DashboardUserColumnsProvider;
use rafalmasiarek\DashboardKit\Schema\ModuleSchemaBuilder;
use rafalmasiarek\DashboardKit\Schema\SchemaInspector;
use rafalmasiarek\DashboardKit\Schema\SchemaStateManager;
use rafalmasiarek\DashboardKit\Schema\PasswordResetSchemaProvider;
use rafalmasiarek\DashboardKit\Schema\SeedRunner;
use rafalmasiarek\DashboardKit\Schema\UserFieldsSchemaProvider;
use rafalmasiarek\DashboardKit\Model\Model;
use rafalmasiarek\DashboardKit\Utils\UuidUserIdPolicy;
use DI\Container;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use rafalmasiarek\DashboardKit\Log\LoggerFactory;
use rafalmasiarek\DashboardKit\Log\RequestHeadersProcessor;
use rafalmasiarek\DashboardKit\Log\RequestLogSanitizer;
use rafalmasiarek\DashboardKit\Mail\Driver\NullDriver;
use rafalmasiarek\DashboardKit\Mail\Driver\SmtpDriver;
use rafalmasiarek\DashboardKit\Mail\Mailer;
use rafalmasiarek\DashboardKit\Utils\PasswordStrength;
use rafalmasiarek\Csrf\Csrf;
use rafalmasiarek\RealIpResolver;
use rafalmasiarek\RealIpResolver\TrustedProxy;
use rafalmasiarek\DashboardKit\Controllers\AuthController;
use rafalmasiarek\DashboardKit\Controllers\PasswordResetController;
use rafalmasiarek\DashboardKit\Controllers\SettingsController;
use rafalmasiarek\DashboardKit\Controllers\UserController;
use rafalmasiarek\DashboardKit\Middleware\AuthMiddleware;
use rafalmasiarek\DashboardKit\Middleware\CsrfMiddleware;
use rafalmasiarek\DashboardKit\Middleware\RequestHeadersMiddleware;
use rafalmasiarek\DashboardKit\Middleware\RoleMiddleware;
use rafalmasiarek\DashboardKit\Middleware\SessionMiddleware;
use rafalmasiarek\DashboardKit\Transport\LazyPhpSessionTransport;
use rafalmasiarek\DashboardKit\Module\ModuleRegistry;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use rafalmasiarek\DashboardKit\Twig\CsrfExtension;
use rafalmasiarek\DashboardKit\Twig\DashboardExtension;
use Throwable;
use Twig\Loader\FilesystemLoader;

/**
 * Entry point factory for building a dashboard application.
 *
 * Usage:
 *   Dashboard::create(__DIR__ . '/../', ['app_name' => 'My App'])->run();
 *
 * @package rafalmasiarek\DashboardKit
 */
class Dashboard
{
    /**
     * @param App                                                               $app      Slim application instance.
     * @param Container                                                         $container PHP-DI container.
     * @param string                                                            $logsDir  Absolute path to the logs directory.
     * @param array<string, array{path: string, level: string, days: int}>      $channels Resolved channel configs.
     */
    private function __construct(
        private readonly App       $app,
        private readonly Container $container,
        private readonly string    $logsDir,
        private readonly array     $channels,
    ) {
    }

    /**
     * Builds and wires the full Slim application.
     *
     * Supported config keys:
     *   app_name      (string) Displayed in navbar and page title. Default: 'App'.
     *   modules_dir         (string) Absolute path to the user modules directory.
     *   admin_modules_dir   (string) Absolute path to the admin modules directory. Default: {rootDir}/admin_modules.
     *                                Each subfolder must contain a module.php returning an array with at least 'slug'.
     *                                Supported keys: slug, title, icon, description, order, navbar, render, handle, routes, settings.
     *                                'routes' are registered under /admin/{slug} and protected by admin role.
     *                                Optional 'settings' key registers a user-level section under /settings/{slug}:
     *                                  title         (string) Section title in the settings navigation.
     *                                  order         (int)    Sort order in settings navigation. Default: 100.
     *                                  templates_dir (string) Absolute path to settings templates; namespace @{slug}-settings.
     *                                  routes        (array)  Same handler format as module 'routes'. Mounted under
     *                                                         /settings/{slug} with AuthMiddleware + CsrfMiddleware only.
     *   templates_dir       (string) Absolute path to user templates that override built-ins.
     *   logs_dir            (string) Base directory for log files. Default: {rootDir}/storage/logs.
     *   logging             (array)  Per-channel overrides. Built-in channels: app, audit, error.
     *                                Each entry: ['path' => 'storage/logs/app.log', 'level' => 'info', 'days' => 30].
     *                                Path may be absolute or relative to logs_dir. Extra channels are
     *                                registered as logger.{name} in the container.
     *                                Special key request_headers (global or per-channel) injects request
     *                                headers into every log record for that channel:
     *                                  []        — no injection (default)
     *                                  '*'       — all request headers (prefixed req.*)
     *                                  string[]  — specific headers, e.g. ['X-Forwarded-For', 'User-Agent']
     *                                Global:     'logging' => ['request_headers' => [...], ...]
     *                                Per-channel:'logging' => ['audit' => ['request_headers' => [...], ...]]
     *   env                 (string) 'dev' enables Whoops; anything else uses Twig error pages.
     *   registration         (bool)   When true, registers the /register route. Default: false.
     *   password_reset       (bool)   When true, registers /forgot-password and /reset-password routes. Default: false.
     *   require_activation   (bool)     When true, new registrations require email activation before login is allowed.
     *                                   Wires /activate/{token}, /mail/track/{token}, login check, and activation email
     *                                   (requires mailer to be configured). Default: false.
     *   before_login         (callable) Called before credentials are checked on POST /login.
     *                                   Signature: (ServerRequestInterface): ?string
     *                                   Return null to allow, or a string error message to block.
     *                                   Can also be overridden after create() via:
     *                                   $dashboard->getContainer()->set('auth.before_login', callable).
     *   before_register      (callable) Called before account creation on POST /register.
     *                                   Same signature as before_login.
     *
     * Database connection is read from environment variables:
     *   DB_HOST, DB_NAME, DB_USER, DB_PASS
     *
     *
     * If rafalmasiarek/dashboard-kit-scheduler is installed, the scheduler addon
     * is wired automatically — no additional configuration needed.
     *
     * @param  string               $rootDir Absolute path to the application root.
     * @param  array<string, mixed> $config  Optional configuration overrides.
     * @return self
     */
    public static function create(string $rootDir, array $config = []): self
    {
        $isDev     = (string) ($config['env'] ?? getenv('APP_ENV') ?: 'prod') === 'dev';

        if ($isDev && class_exists(\Whoops\Run::class)) {
            $prettyHandler    = new \Whoops\Handler\PrettyPageHandler();
            $sensitivePattern = '/(SECRET|TOKEN|PASSWORD|PASSWD|PWD|_PASS|_KEY|_HASH|_SALT|_PEM|_KEK|CREDENTIAL|API[_-]?KEY)/i';
            foreach (\array_keys(\array_merge($_ENV, $_SERVER)) as $envKey) {
                if (\preg_match($sensitivePattern, (string) $envKey)) {
                    $prettyHandler->blacklist('_ENV', $envKey);
                    $prettyHandler->blacklist('_SERVER', $envKey);
                }
            }
            $whoops = new \Whoops\Run();
            $whoops->allowQuit(false);
            $whoops->writeToOutput(true);
            $whoops->pushHandler($prettyHandler);
            $whoops->register();
        }

        $container = self::buildContainer($rootDir, $config, $isDev);

        Model::setConnectionResolver(static fn() => $container->get(PDO::class));

        SecretRegistry::primeFromConfig($config);
        SecretRegistry::primeFromEnv();

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $basePath = \rtrim((string) ($config['app']['base_path'] ?? ''), '/');
        if ($basePath !== '') {
            $app->setBasePath($basePath);
        }

        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->add(new SessionMiddleware());
        $app->add(TwigMiddleware::createFromContainer($app));
        $app->add(function ($req, $handler) use ($container) {
            $env = $container->get('view')->getEnvironment();
            $env->addGlobal('current_path', $req->getUri()->getPath());
            $env->addGlobal('auth', $container->get(Auth::class));
            return $handler->handle($req);
        });

        // Resolve Auth eagerly so AuthKit runs its schema migrations (creates the
        // users table) before module schemas that declare FK references to users.
        $container->get(Auth::class);

        self::initModuleSchemas($container, $config);
        self::registerRoutes($app, $container, $config);

        // Auto-wire scheduler addon when rafalmasiarek/dashboard-kit-scheduler is installed.
        if (class_exists(\rafalmasiarek\DashboardKitScheduler\SchedulerAddon::class)) {
            \rafalmasiarek\DashboardKitScheduler\SchedulerAddon::register($app, $container);
        }

        // Auto-wire API addon when rafalmasiarek/dashboard-kit-api is installed.
        if (class_exists(\rafalmasiarek\DashboardKitApi\ApiAddon::class)) {
            \rafalmasiarek\DashboardKitApi\ApiAddon::register($app, $container);
        }

        // Seeds run after all addons have registered their schemas so that
        // addon-owned tables (e.g. user_tokens from ApiAddon) exist on first boot.
        self::runSeeds($container, $config);

        // Error middleware must be added last (it wraps everything as the outermost layer).
        self::registerErrorHandlers($app, $container, $isDev);

        $app->add($container->get(RequestHeadersMiddleware::class));
        $app->add($container->get(RequestTimingMiddleware::class));

        $logsDir  = (string) ($config['logs_dir'] ?? $rootDir . '/storage/logs');
        $channels = self::resolveLogChannels((array) ($config['logging'] ?? []), $logsDir);

        return new self($app, $container, $logsDir, $channels);
    }

    /**
     * Runs the application. Call this as the last statement in public/index.php.
     */
    public function run(): void
    {
        $this->app->run();
    }

    /**
     * Returns the underlying Slim application for advanced customisation.
     *
     * Allows adding middleware or routes after Dashboard::create():
     *   $dashboard->getApp()->add(new MyMiddleware());
     *
     * @return App
     */
    public function getApp(): App
    {
        return $this->app;
    }

    /**
     * Registers a hook listener for a dashboard event.
     *
     * Supported events and their listener signatures:
     *   register         (User $user)
     *   login            (User $user)
     *   logout           (User $user)
     *   password_changed (User $user)
     *   email_changed    (User $user, string $oldEmail)
     *   profile_updated  (User $user, array $updatedFields)
     *   user_suspended   (User $target, User $admin)
     *   user_unsuspended (User $target, User $admin)
     *   role_changed     (User $target, string $oldRole, string $newRole, User $admin)
     *   user_updated     (User $target, string[] $changedFields, User $admin)
     *   user_deleted     (User $target, User $admin)
     *
     * @param  string   $event    Event name.
     * @param  callable $listener Callback invoked after the event fires.
     * @return self
     */
    public function on(string $event, callable $listener): self
    {
        $this->container->get(HookRegistry::class)->on($event, $listener);
        return $this;
    }

    /**
     * Returns the DI container for registering additional services.
     *
     * Returns the concrete PHP-DI Container (not ContainerInterface) so that
     * callers can invoke set() to override or extend bindings before run().
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Resolves the logging channel configs, merging user overrides with built-in defaults.
     *
     * Default channels and their defaults:
     *   app   — {logs_dir}/app.log,   level: info,  days: 30
     *   audit — {logs_dir}/audit.log, level: info,  days: 90
     *   error — {logs_dir}/error.log, level: error, days: 30
     *
     * A path without a leading slash is treated as relative to $logsDir.
     * Extra channels defined by the user are appended after the built-in ones.
     *
     * @param  array<string, mixed> $raw     User-supplied 'logging' config key.
     * @param  string               $logsDir Resolved absolute logs directory.
     * @return array<string, array{path: string, level: string, days: int}>
     */
    private static function resolveLogChannels(array $raw, string $logsDir): array
    {
        $defaults = [
            'app'    => ['path' => 'app.log',    'level' => LogLevel::INFO,  'days' => 30],
            'system' => ['path' => 'system.log', 'level' => LogLevel::DEBUG, 'days' => 30],
            'audit'  => ['path' => 'audit.log',  'level' => LogLevel::INFO,  'days' => 90],
            'error'  => ['path' => 'error.log',  'level' => LogLevel::ERROR, 'days' => 30],
        ];

        $channels = [];

        foreach ($defaults as $name => $default) {
            $override = (array) ($raw[$name] ?? []);
            $cfg      = array_merge($default, $override);
            $cfg['path'] = self::absoluteLogPath($cfg['path'], $logsDir);
            $channels[$name] = $cfg;
        }

        foreach ($raw as $name => $cfg) {
            if (isset($channels[$name]) || $name === 'request_headers') {
                continue;
            }
            $cfg = array_merge(['level' => LogLevel::DEBUG, 'days' => 30], (array) $cfg);
            $cfg['path'] = self::absoluteLogPath($cfg['path'] ?? ($name . '.log'), $logsDir);
            $channels[$name] = $cfg;
        }

        return $channels;
    }

    /**
     * Returns an absolute path for a log file.
     *
     * @param  string $path    File path (absolute or relative to $logsDir).
     * @param  string $logsDir Base directory.
     * @return string
     */
    private static function absoluteLogPath(string $path, string $logsDir): string
    {
        return str_starts_with($path, '/') ? $path : $logsDir . '/' . $path;
    }

    /**
     * Normalises the user_fields config array to a consistent shape.
     *
     * Each field entry is resolved to:
     *   label    (string)
     *   type     (string, default 'text')
     *   max      (int,    default 255)
     *   required (bool,   default false)
     *   options  (array<string,string>, only for type='select')
     *
     * A plain string value is treated as the label with all defaults applied.
     *
     * @param  array<string, mixed> $raw
     * @return array<string, array{label: string, type: string, max: int, required: bool, options: array<string,string>}>
     */
    private static function normalizeUserFields(array $raw): array
    {
        $fields = [];
        foreach ($raw as $name => $def) {
            if (is_string($def)) {
                $def = ['label' => $def];
            }
            $fields[(string) $name] = [
                'label'    => (string) ($def['label']    ?? $name),
                'type'     => (string) ($def['type']     ?? 'text'),
                'max'      => (int)    ($def['max']       ?? 255),
                'required' => (bool)   ($def['required']  ?? false),
                'options'  => is_array($def['options'] ?? null) ? $def['options'] : [],
            ];
        }
        return $fields;
    }

    /**
     * Builds and populates the DI container.
     *
     * @param  string               $rootDir
     * @param  array<string, mixed> $config
     * @param  bool                 $isDev   Determines default log level: DEBUG in dev, WARNING in prod.
     * @return Container
     */
    private static function buildContainer(string $rootDir, array $config, bool $isDev = false): Container
    {
        $appName         = (string) ($config['app_name'] ?? 'App');
        $modulesDir      = (string) ($config['modules_dir'] ?? $rootDir . '/modules');
        $adminModulesDir = (string) ($config['admin_modules_dir'] ?? $rootDir . '/admin_modules');
        $userTplDir      = isset($config['templates_dir']) ? (string) $config['templates_dir'] : null;
        $pkgTplDir       = __DIR__ . '/../templates';
        $pkgModulesDir   = __DIR__ . '/../modules';
        $userFields      = self::normalizeUserFields((array) ($config['user_fields'] ?? []));
        $logsDir  = (string) ($config['logs_dir'] ?? $rootDir . '/storage/logs');
        $channels = self::resolveLogChannels((array) ($config['logging'] ?? []), $logsDir);

        $pwConfig         = (array) ($config['password_strength'] ?? []);
        $pwRuleOverrides  = (array) ($pwConfig['rules'] ?? []);
        $passwordStrength = [
            'enabled'   => (bool) ($pwConfig['enabled']   ?? false),
            'min_score' => (int)  ($pwConfig['min_score'] ?? 2),
            'rules'     => empty($pwRuleOverrides)
                ? PasswordStrength::DEFAULT_RULES
                : array_replace_recursive(PasswordStrength::DEFAULT_RULES, $pwRuleOverrides),
        ];

        $allowRegistration  = (bool) ($config['registration']        ?? false);
        $allowPasswordReset = (bool) ($config['password_reset']      ?? false);
        $requireActivation  = (bool) ($config['require_activation']  ?? false);
        $mailerConfig       = (array) ($config['mailer'] ?? []);
        $beforeLogin        = isset($config['before_login'])    && is_callable($config['before_login'])    ? $config['before_login']    : null;
        $beforeRegister     = isset($config['before_register']) && is_callable($config['before_register']) ? $config['before_register'] : null;

        $rawDashboardPrefix   = (string) ($config['dashboard']['prefix'] ?? '');
        $dashboardRoutePrefix = $rawDashboardPrefix === '' ? '' : '/' . \ltrim($rawDashboardPrefix, '/');
        $basePath             = \rtrim((string) ($config['app']['base_path'] ?? ''), '/');
        $dashboardUrlPrefix   = $basePath . $dashboardRoutePrefix;
        $rawAdminPrefix       = (string) ($config['dashboard']['admin_prefix'] ?? 'admin');
        $adminPanelUrlPrefix  = $dashboardUrlPrefix . '/' . \ltrim($rawAdminPrefix, '/');

        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }

        $container = new Container();

        $container->set('dashboard.url_prefix',        $dashboardUrlPrefix);
        $container->set('dashboard.admin_panel_prefix', $adminPanelUrlPrefix);
        $container->set('app.url_base_path',            $basePath);

        $rawLogging       = (array) ($config['logging'] ?? []);
        $globalReqHeaders = $rawLogging['request_headers'] ?? [];

        $ipResolverFn = static fn(): string => ($container->get(RealIpResolver::class)->getIp() ?: 'unknown');

        $channelProcessors = [];
        foreach ($channels as $name => $cfg) {
            if ($name === 'system') {
                continue;
            }
            $headers                  = $cfg['request_headers'] ?? $globalReqHeaders;
            $channelProcessors[$name] = new RequestHeadersProcessor($headers, $ipResolverFn);
        }

        foreach ($channels as $name => $cfg) {
            $enabled = (bool) ($cfg['enabled'] ?? true);

            if ($name === 'system') {
                $container->set('logger.system', static function () use ($cfg, $enabled) {
                    if (!$enabled) {
                        return new \Psr\Log\NullLogger();
                    }
                    return LoggerFactory::create('system', $cfg['path'], $cfg['level'], $cfg['days']);
                });
                continue;
            }

            $processor = $channelProcessors[$name];
            $container->set('logger.' . $name, static function () use ($name, $cfg, $processor, $enabled) {
                if (!$enabled) {
                    return new \Psr\Log\NullLogger();
                }
                $logger = LoggerFactory::create($name, $cfg['path'], $cfg['level'], $cfg['days']);
                $logger->pushProcessor($processor);
                return $logger;
            });
        }
        $container->set(RequestHeadersMiddleware::class,
            static fn() => new RequestHeadersMiddleware(...array_values($channelProcessors))
        );
        $appEnabled = (bool) ($channels['app']['enabled'] ?? true);
        $container->set(RequestLogSanitizer::class,
            static fn() => new RequestLogSanitizer((string) \getenv('APP_KEY'))
        );
        $container->set(RequestTimingMiddleware::class, static function () use ($container, $appEnabled) {
            $logger = $appEnabled ? $container->get('logger.app') : new \Psr\Log\NullLogger();
            return new RequestTimingMiddleware($logger, $container->get(RequestLogSanitizer::class));
        });

        $pdoDsn  = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_NAME') ?: 'app'
        );
        $pdoUser = getenv('DB_USER') ?: 'root';
        $pdoPass = getenv('DB_PASS') ?: 'root';
        $pdoOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        // Raw connection used by system infrastructure (PdoQueryCacheDriver).
        // Bypasses CachingPdo to avoid a circular dependency during container resolution.
        $container->set('pdo.raw', static fn() => new PDO($pdoDsn, $pdoUser, $pdoPass, $pdoOpts));

        $container->set(QueryCacheDriverInterface::class, static fn(ContainerInterface $c) =>
            new PdoQueryCacheDriver($c->get('pdo.raw'))
        );

        $tablePrefix = (string) ($config['table_prefix'] ?? '');
        $container->set('db.table_prefix', static fn() => $tablePrefix);

        // Application-facing PDO: caches SELECT results and invalidates by tag on writes.
        $container->set(PDO::class, static fn(ContainerInterface $c) =>
            new CachingPdo(
                $pdoDsn,
                $pdoUser,
                $pdoPass,
                $pdoOpts,
                $c->get(QueryCacheDriverInterface::class),
                300,
                $tablePrefix,
            )
        );

        $container->set(Auth::class, static function (ContainerInterface $c) use ($userFields, $requireActivation, $allowPasswordReset, $mailerConfig) {
            $pdo     = $c->get(PDO::class);
            $storage = new PdoUserStorage($pdo, new UuidUserIdPolicy());

            $suspendedExt = new SuspendedUserExtension();
            $activeExt    = $requireActivation ? new ActiveUserExtension() : null;

            $auth = new Auth($storage, null, 3600, null, true, null, null, null, new LazyPhpSessionTransport());
            $auth->addLoginExtension($suspendedExt);
            if ($activeExt !== null) {
                $auth->addLoginExtension($activeExt);
            }

            $mailer = !empty($mailerConfig) ? $c->get(Mailer::class) : null;
            $auth->createSchema(
                new DashboardUserColumnsProvider(),
                new UserFieldsSchemaProvider($userFields),
                ...($allowPasswordReset ? [new PasswordResetSchemaProvider()] : []),
                ...($mailer !== null ? [$mailer] : []),
            );

            return $auth;
        });

        $container->set('app.root_dir', static fn() => $rootDir);
        $container->set('app.config', static fn() => $config);

        // RealIpResolver — resolves the real client IP behind trusted proxies.
        //
        // app.config['trusted_proxies'] — array of IPs / CIDR ranges of trusted proxies.
        // app.config['trusted_headers']  — headers to trust when REMOTE_ADDR is a trusted proxy.
        //   Supported values: 'x_forwarded_for', 'cloudflare', 'rfc7239', 'x_real_ip'.
        //   Defaults to ['x_forwarded_for'] when trusted_proxies is non-empty.
        //
        // To use a custom provider (e.g. with Cloudflare IP list), override the binding
        // after Dashboard::create(): $container->set(RealIpResolver::class, fn() => ...).
        $trustedProxies = (array) ($config['trusted_proxies'] ?? []);
        $trustedHeaders = isset($config['trusted_headers'])
            ? (array) $config['trusted_headers']
            : (empty($trustedProxies) ? [] : ['x_forwarded_for']);

        $container->set(RealIpResolver::class, static function () use ($trustedProxies, $trustedHeaders): RealIpResolver {
            $resolver = empty($trustedProxies)
                ? new RealIpResolver()
                : new RealIpResolver(new TrustedProxy($trustedProxies));

            foreach ($trustedHeaders as $header) {
                match ((string) $header) {
                    'x_forwarded_for' => $resolver->enableXForwardedForHeader(),
                    'cloudflare'      => $resolver->enableCloudflareHeader(),
                    'rfc7239'         => $resolver->enableRFC7239(),
                    'x_real_ip'       => $resolver->enableXRealIpHeader(),
                    default           => null,
                };
            }

            return $resolver;
        });
        $container->set('user_fields', fn() => $userFields);

        $container->set(Flash::class, static fn() => new Flash());

        $container->set(Csrf::class, static function () {
            $keyRaw = (string) (getenv('APP_KEY') ?: '');
            if ($keyRaw === '') {
                throw new \RuntimeException(
                    'APP_KEY environment variable is required. ' .
                    'Generate with: php -r "echo bin2hex(random_bytes(16));"'
                );
            }
            // Accept a 64-char hex string (hex of 32 bytes) or a raw 32-char key.
            $key = (strlen($keyRaw) === 64 && ctype_xdigit($keyRaw))
                ? hex2bin($keyRaw)
                : substr(str_pad($keyRaw, 32, "\0"), 0, 32);

            return new Csrf($key, 900);
        });

        $container->set(CsrfMiddleware::class, static fn(ContainerInterface $c) =>
            new CsrfMiddleware($c->get(Csrf::class))
        );

        $container->set(ModuleRegistry::class, static function () use ($pkgModulesDir, $modulesDir, $container) {
            $registry = new ModuleRegistry();
            $registry->discover($pkgModulesDir);
            if (is_dir($modulesDir)) {
                $registry->discover($modulesDir);
            }

            if ($container->has('logger.system')) {
                $slugs = \array_keys($registry->all());
                $container->get('logger.system')->debug('modules.loaded', [
                    'source' => 'modules',
                    'count'  => \count($slugs),
                    'slugs'  => \implode(',', $slugs),
                ]);
            }

            return $registry;
        });

        $container->set('admin_module_registry', static function () use ($adminModulesDir, $container) {
            $registry = new ModuleRegistry();
            if (is_dir($adminModulesDir)) {
                $registry->discover($adminModulesDir);
            }

            if ($container->has('logger.system')) {
                $slugs = \array_keys($registry->all());
                if ($slugs !== []) {
                    $container->get('logger.system')->debug('modules.loaded', [
                        'source' => 'admin_modules',
                        'count'  => \count($slugs),
                        'slugs'  => \implode(',', $slugs),
                    ]);
                }
            }

            return $registry;
        });

        $container->set(SettingsSectionRegistry::class, static fn() => new SettingsSectionRegistry());
        $container->set(UserActionRegistry::class,      static fn() => new UserActionRegistry());
        $container->set(FormSlotRegistry::class,        static fn() => new FormSlotRegistry());

        $container->set('password_strength', static fn() => $passwordStrength);

        $container->set('view', static function (ContainerInterface $c) use ($appName, $userTplDir, $pkgTplDir, $userFields, $passwordStrength, $allowRegistration, $allowPasswordReset, $dashboardUrlPrefix, $adminPanelUrlPrefix, $basePath) {
            $loader = new FilesystemLoader();

            // User templates override package templates at the default namespace.
            if ($userTplDir !== null && is_dir($userTplDir)) {
                $loader->addPath($userTplDir);
            }
            $loader->addPath($pkgTplDir);

            // Register the package templates under @dashboard-kit so user templates can
            // extend or include core templates without infinite recursion:
            //   {% extends '@dashboard-kit/layout.twig' %}
            $loader->addPath($pkgTplDir, 'dashboard-kit');

            // Register per-module template namespaces so modules can use @slug/file.twig.
            foreach ($c->get(ModuleRegistry::class)->all() as $slug => $module) {
                if (!empty($module['_templates_dir'])) {
                    $loader->addPath($module['_templates_dir'], $slug);
                }
            }
            foreach ($c->get('admin_module_registry')->all() as $slug => $module) {
                if (!empty($module['_templates_dir'])) {
                    $loader->addPath($module['_templates_dir'], $slug);
                }
            }

            // Module settings: register template namespaces and settings section entries.
            $sectionRegistry = $c->get(SettingsSectionRegistry::class);
            foreach ($c->get('admin_module_registry')->all() as $slug => $module) {
                if (empty($module['settings']) || !\is_array($module['settings'])) {
                    continue;
                }
                $sect   = $module['settings'];
                $tplDir = $sect['templates_dir'] ?? null;
                if ($tplDir !== null && \is_dir((string) $tplDir)) {
                    $loader->addPath((string) $tplDir, $slug . '-settings');
                }
                $sectionRegistry->register($slug, [
                    'title' => (string) ($sect['title'] ?? $slug),
                    'path'  => $dashboardUrlPrefix . '/settings/' . $slug,
                    'order' => (int) ($sect['order'] ?? 100),
                ]);
            }

            $twig = new Twig($loader, ['cache' => false]);
            $env  = $twig->getEnvironment();
            $env->addExtension(new CsrfExtension($c->get(Csrf::class)));
            $env->addExtension(new DashboardExtension());
            $env->addGlobal('user_fields', $userFields);
            $env->addGlobal('app_name', $appName);
            $env->addGlobal('password_strength', $passwordStrength);
            $env->addGlobal('registration_enabled', $allowRegistration);
            $env->addGlobal('password_reset_enabled', $allowPasswordReset);
            $env->addGlobal('flash', $c->get(Flash::class));
            $navItems = array_filter(
                $c->get(ModuleRegistry::class)->navbarItems(),
                static fn($m) => ($m['slug'] ?? null) !== 'home'
            );
            $adminNavPrefix = \substr($adminPanelUrlPrefix, \strlen($dashboardUrlPrefix));
            $adminNavItems  = [];
            foreach ($c->get('admin_module_registry')->navbarItems() as $slug => $m) {
                if ($slug === 'home') {
                    continue;
                }
                $m['path']          = $adminNavPrefix . ($m['path'] ?? '/' . $slug);
                $m['auth_required'] = $m['auth_required'] ?? true;
                $adminNavItems[$slug] = $m;
            }
            $env->addGlobal('modules', \array_merge($navItems, $adminNavItems));
            $allAdminModules = $c->get('admin_module_registry')->all();
            unset($allAdminModules['home']);
            $env->addGlobal('admin_modules', $allAdminModules);
            $env->addGlobal('settings_sections', $c->get(SettingsSectionRegistry::class)->all());
            $env->addGlobal('user_actions', $c->get(UserActionRegistry::class)->all());
            $env->addGlobal('dash', $dashboardUrlPrefix);
            $env->addGlobal('admin_panel', $adminPanelUrlPrefix);
            $env->addGlobal('base', $basePath);
            $env->addFunction(new \Twig\TwigFunction('form_slot', static function (string $form, string $slot) use ($c): \Twig\Markup {
                return new \Twig\Markup($c->get(FormSlotRegistry::class)->render($form, $slot), 'UTF-8');
            }));

            return $twig;
        });

        $container->set(AuthMiddleware::class, static fn(ContainerInterface $c) =>
            new AuthMiddleware($c->get(Auth::class), $c->get('dashboard.url_prefix'))
        );

        $container->set(HookRegistry::class, static function (ContainerInterface $c) use ($requireActivation) {
            $hooks = new HookRegistry();
            $flash = $c->get(Flash::class);
            if (!$requireActivation) {
                $hooks->on('register', static function (\AuthKit\User $user) use ($c, $flash) {
                    $c->get(PDO::class)->prepare('UPDATE users SET active = 1 WHERE id = ?')
                      ->execute([$user->get('id')]);
                    $flash->add('success', 'Account created. You can now log in.');
                });
            }
            $hooks->on('logout', static fn() => $flash->add('success', 'You have been logged out.'));
            return $hooks;
        });

        $container->set(AuditLog::class, static fn(ContainerInterface $c) =>
            new AuditLog($c->get('logger.audit'))
        );

        $container->set(UserController::class, static fn(ContainerInterface $c) =>
            new UserController($c->get('view'), $c->get(Auth::class), $c->get(Flash::class), $c->get(PDO::class), $c->get('user_fields'), $c->get(HookRegistry::class), $c->get(AuditLog::class), false, $c->get('dashboard.admin_panel_prefix'))
        );

        $container->set(SettingsController::class, static fn(ContainerInterface $c) =>
            new SettingsController($c->get('view'), $c->get(Auth::class), $c->get(Flash::class), $c->get(PDO::class), $c->get('user_fields'), $c->get(HookRegistry::class), $c->get(AuditLog::class), $c->get('dashboard.url_prefix'))
        );

        if (!empty($mailerConfig)) {
            $container->set(Mailer::class, static function (ContainerInterface $c) use ($mailerConfig, $appName) {
                $driver = match ($mailerConfig['driver'] ?? 'null') {
                    'smtp'  => new SmtpDriver((array) ($mailerConfig['smtp'] ?? [])),
                    default => new NullDriver(),
                };
                return new Mailer(
                    $driver,
                    $c->get('view'),
                    (string) ($mailerConfig['from_email'] ?? ''),
                    (string) ($mailerConfig['from_name']  ?? $appName),
                    $c->get(HookRegistry::class),
                    $c->get(AuditLog::class),
                );
            });
        }

        $container->set(PasswordResetController::class, static function (ContainerInterface $c) use ($passwordStrength, $mailerConfig) {
            $mailer = !empty($mailerConfig) ? $c->get(Mailer::class) : null;
            return new PasswordResetController(
                $c->get('view'),
                $c->get(Auth::class),
                $c->get(Flash::class),
                $c->get(PDO::class),
                $c->get(HookRegistry::class),
                $c->get(AuditLog::class),
                $passwordStrength,
                $mailer,
                $c->get('dashboard.url_prefix'),
            );
        });

        $container->set('auth.before_login',    static fn() => $beforeLogin);
        $container->set('auth.before_register', static fn() => $beforeRegister);

        $container->set(AuthController::class, static function (ContainerInterface $c) use ($requireActivation, $mailerConfig) {
            return new AuthController(
                $c->get('view'),
                $c->get(Auth::class),
                $c->get(Flash::class),
                $c->get('user_fields'),
                $c->get(HookRegistry::class),
                $c->get(AuditLog::class),
                $c->get(PDO::class),
                $c->get('password_strength'),
                $requireActivation,
                !empty($mailerConfig) ? $c->get(Mailer::class) : null,
                $c->get('auth.before_login'),
                $c->get('auth.before_register'),
                $c->get('dashboard.url_prefix'),
            );
        });

        return $container;
    }

    /**
     * Syncs all module schemas against the live database via SchemaStateManager.
     *
     * Processes module schemas from both public and admin registries, plus any
     * top-level 'schema' key defined in $config (tables that do not belong to
     * any module — stored under the pseudo-slug '_bootstrap').
     *
     * Runs before route registration so all tables are guaranteed to exist on
     * the first request. Uses a hash-based state table (_schema_state) to skip
     * tables whose schema has not changed — no INFORMATION_SCHEMA queries on
     * the happy path.
     *
     * @param ContainerInterface   $container PSR-11 container.
     * @param array<string, mixed> $config    Application config.
     */
    private static function initModuleSchemas(ContainerInterface $container, array $config): void
    {
        $tablePrefix = (string) ($config['table_prefix'] ?? '');

        $manager = new SchemaStateManager(
            $container->get('pdo.raw'),
            new ModuleSchemaBuilder(),
            new SchemaInspector(),
            $tablePrefix,
        );

        $modules = [];

        $rootDir = (string) $container->get('app.root_dir');

        foreach ([
            $container->get(ModuleRegistry::class),
            $container->get('admin_module_registry'),
        ] as $registry) {
            foreach ($registry->all() as $slug => $module) {
                if (isset($module['schema']) && \is_array($module['schema'])) {
                    $modules[(string) $slug] = $module;
                }

                foreach ((array) ($module['sqlite'] ?? []) as $entry) {
                    $path = $rootDir . '/' . \ltrim((string) ($entry['path'] ?? ''), '/');
                    $pdo  = new \PDO('sqlite:' . $path);
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    (new SchemaStateManager($pdo, new ModuleSchemaBuilder(), new SchemaInspector()))
                        ->sync([(string) $slug => ['schema' => (array) ($entry['schema'] ?? [])]]);
                    $key = (string) ($entry['key'] ?? $slug . '.sqlite');
                    $container->set($key, static fn() => $pdo);
                }
            }
        }

        $topSchema = (array) ($config['schema'] ?? []);
        if ($topSchema !== []) {
            $modules['_bootstrap'] = ['schema' => $topSchema];
        }

        $manager->sync($modules);
    }

    /**
     * Runs declarative seed definitions from the 'seed' config key.
     *
     * Seeds are idempotent — each row is skipped when a matching record already
     * exists. Runs after schema sync so all target tables are guaranteed to exist.
     *
     * Uses the raw PDO connection to avoid boot-time interactions with CachingPdo.
     *
     * @param ContainerInterface   $container PSR-11 container.
     * @param array<string, mixed> $config    Application config.
     */
    private static function runSeeds(ContainerInterface $container, array $config): void
    {
        $seeds = (array) ($config['seed'] ?? []);

        if ($seeds === []) {
            return;
        }

        $tablePrefix = (string) ($config['table_prefix'] ?? '');

        (new SeedRunner($container->get('pdo.raw'), $tablePrefix))->run($seeds);
    }

    /**
     * Registers all routes: auth, root redirect, module pages and API endpoints.
     *
     * Routes are placed under the dashboard prefix group (config: dashboard.prefix).
     * The inner admin group uses a separate prefix (config: dashboard.admin_prefix).
     * Public-facing routes (modules with auth_required: false) are also inside the
     * dashboard prefix group, consistent with the overall URL structure.
     *
     * @param App                  $app
     * @param ContainerInterface   $container PSR-11 container.
     * @param array<string, mixed> $config    Resolved application config.
     */
    private static function registerRoutes(App $app, ContainerInterface $container, array $config): void
    {
        $allowRegistration  = (bool) ($config['registration']       ?? false);
        $allowPasswordReset = (bool) ($config['password_reset']     ?? false);
        $requireActivation  = (bool) ($config['require_activation'] ?? false);

        $rawDashboardPrefix  = (string) ($config['dashboard']['prefix'] ?? '');
        $dashboardRoutePrefix = $rawDashboardPrefix === '' ? '' : '/' . \ltrim($rawDashboardPrefix, '/');

        $rawAdminPrefix  = (string) ($config['dashboard']['admin_prefix'] ?? 'admin');
        $adminRoutePrefix = '/' . \ltrim($rawAdminPrefix, '/');

        $dashboardUrlPrefix = $container->get('dashboard.url_prefix');

        $registry      = $container->get(ModuleRegistry::class);
        $homeModule    = $registry->all()['home'] ?? null;
        $allModules    = array_filter($registry->all(), fn($m) => ($m['slug'] ?? null) !== 'home');
        $authModules   = array_filter($allModules, fn($m) => $m['auth_required'] ?? true);
        $publicModules = array_filter($allModules, fn($m) => !($m['auth_required'] ?? true));
        $adminRegistry = $container->get('admin_module_registry');

        if ($dashboardRoutePrefix !== '') {
            $app->get($dashboardRoutePrefix, function ($req, $res) use ($dashboardUrlPrefix) {
                return $res->withHeader('Location', $dashboardUrlPrefix . '/')->withStatus(301);
            });
        }

        $app->group($dashboardRoutePrefix, function (RouteCollectorProxy $dash) use (
            $allowRegistration,
            $allowPasswordReset,
            $requireActivation,
            $container,
            $adminRoutePrefix,
            $dashboardUrlPrefix,
            $authModules,
            $publicModules,
            $adminRegistry,
            $homeModule
        ) {
            $dash->map(['GET', 'POST'], '/login', [AuthController::class, 'login'])
                ->add(CsrfMiddleware::class);

            if ($allowRegistration) {
                $dash->map(['GET', 'POST'], '/register', [AuthController::class, 'register'])
                    ->add(CsrfMiddleware::class);
            }

            $dash->get('/logout', [AuthController::class, 'logout']);

            if ($allowPasswordReset) {
                $dash->get('/forgot-password', [PasswordResetController::class, 'requestForm']);
                $dash->post('/forgot-password', [PasswordResetController::class, 'request'])
                    ->add(CsrfMiddleware::class);
                $dash->get('/reset-password/{token}', [PasswordResetController::class, 'resetForm']);
                $dash->post('/reset-password/{token}', [PasswordResetController::class, 'reset'])
                    ->add(CsrfMiddleware::class);
            }

            if ($requireActivation) {
                $dash->get('/activate/{token}', function ($req, $res, $args) use ($container, $dashboardUrlPrefix) {
                    $db    = $container->get(PDO::class);
                    $flash = $container->get(Flash::class);
                    $audit = $container->get(AuditLog::class);

                    $stmt = $db->prepare('SELECT user_id FROM user_activations WHERE token = ?');
                    $stmt->execute([$args['token']]);
                    $userId = $stmt->fetchColumn();

                    if (!$userId) {
                        $audit->activationAttempt(false);
                        $flash->add('danger', 'Invalid or expired activation link.');
                        return $res->withHeader('Location', $dashboardUrlPrefix . '/login')->withStatus(302);
                    }

                    $db->prepare('UPDATE users SET active = 1 WHERE id = ?')->execute([$userId]);
                    $db->prepare('DELETE FROM user_activations WHERE user_id = ?')->execute([$userId]);

                    $emailStmt = $db->prepare('SELECT email FROM users WHERE id = ?');
                    $emailStmt->execute([$userId]);
                    $audit->activationAttempt(true, (int) $userId, (string) $emailStmt->fetchColumn());

                    $flash->add('success', 'Your account has been activated. You can now log in.');
                    return $res->withHeader('Location', $dashboardUrlPrefix . '/login')->withStatus(302);
                });

                $dash->get('/mail/track/{token}', function ($req, $res, $args) use ($container) {
                    $db = $container->get(PDO::class);
                    $ip = $container->get(RealIpResolver::class)->getIp() ?: 'unknown';

                    $stmt = $db->prepare(
                        'SELECT id, to_email, mail_type FROM mail_tracking WHERE token = ? AND opened_at IS NULL'
                    );
                    $stmt->execute([$args['token']]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if ($row) {
                        $db->prepare('UPDATE mail_tracking SET opened_at = NOW(), open_ip = ? WHERE id = ?')
                           ->execute([$ip, $row['id']]);
                        $container->get(AuditLog::class)->mailOpen($row['to_email'], $row['mail_type']);
                    }

                    $res->getBody()->write(
                        base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
                    );

                    return $res
                        ->withHeader('Content-Type', 'image/gif')
                        ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                        ->withHeader('Pragma', 'no-cache');
                });
            }

            $dash->get('/', function ($req, $res) use ($container, $homeModule) {
                $view = $container->get('view');
                $db   = $container->get(PDO::class);

                if ($homeModule !== null && isset($homeModule['render'])) {
                    return ($homeModule['render'])($req, $res, $view, $db, $container);
                }

                return $view->render($res, 'home.twig');
            });

            $dash->group('', function (RouteCollectorProxy $group) use ($authModules, $container) {
                foreach ($authModules as $module) {
                    $authRequired = $module['auth_required'] ?? true;
                    $hasRoleRestriction = is_string($authRequired) || is_array($authRequired);

                    if ($hasRoleRestriction) {
                        $roles = (array) $authRequired;
                        $group->group('', function (RouteCollectorProxy $roleGroup) use ($module, $container) {
                            if (!empty($module['routes'])) {
                                self::addRoutesMap($roleGroup, $module, $container);
                            } else {
                                self::addModuleRoute($roleGroup, $module, $container);
                            }
                        })->add(new RoleMiddleware($container, $roles, $dashboardUrlPrefix));
                    } else {
                        if (!empty($module['routes'])) {
                            self::addRoutesMap($group, $module, $container);
                        } else {
                            self::addModuleRoute($group, $module, $container);
                        }
                    }
                }
            })->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

            foreach ($publicModules as $module) {
                if (!empty($module['routes'])) {
                    self::addRoutesMap($dash, $module, $container);
                } else {
                    self::addModuleRoute($dash, $module, $container);
                }
            }

            $dash->group('/settings', function (RouteCollectorProxy $group) use ($container, $adminRegistry) {
                $group->get('[/]',        [SettingsController::class, 'index']);
                $group->post('/password', [SettingsController::class, 'changePassword']);
                $group->post('/email',    [SettingsController::class, 'changeEmail']);
                $group->post('/profile',  [SettingsController::class, 'updateProfile']);

                foreach ($adminRegistry->all() as $slug => $module) {
                    if (empty($module['settings']['routes']) || !\is_array($module['settings']['routes'])) {
                        continue;
                    }
                    self::addRoutesMap($group, ['slug' => $slug, 'routes' => $module['settings']['routes']], $container);
                }
            })->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

            $adminHomeModule = $adminRegistry->all()['home'] ?? null;

            $dash->group($adminRoutePrefix, function (RouteCollectorProxy $adminGroup) use ($container, $adminRegistry, $adminHomeModule) {
                $adminGroup->get('[/]', function ($req, $res) use ($container, $adminHomeModule) {
                    $view = $container->get('view');
                    $db   = $container->get(PDO::class);

                    if ($adminHomeModule !== null && isset($adminHomeModule['render'])) {
                        return ($adminHomeModule['render'])($req, $res, $view, $db, $container);
                    }

                    return $view->render($res, 'admin/index.twig', ['title' => 'Admin']);
                });

                $adminGroup->group('/users', function (RouteCollectorProxy $group) {
                    $group->get('[/]',               [UserController::class, 'list']);
                    $group->get('/create',           [UserController::class, 'createForm']);
                    $group->post('/create',          [UserController::class, 'create']);
                    $group->get('/{id}/edit',        [UserController::class, 'edit']);
                    $group->post('/{id}/edit',       [UserController::class, 'update']);
                    $group->post('/{id}/suspend',    [UserController::class, 'suspend']);
                    $group->post('/{id}/unsuspend',  [UserController::class, 'unsuspend']);
                    $group->post('/{id}/delete',     [UserController::class, 'delete']);
                });

                foreach ($adminRegistry->all() as $module) {
                    if (($module['slug'] ?? null) === 'home') {
                        continue;
                    }
                    if (!empty($module['routes'])) {
                        self::addRoutesMap($adminGroup, $module, $container);
                    } else {
                        self::addModuleRoute($adminGroup, $module, $container);
                    }
                }
            })->add(new RoleMiddleware($container, ['admin'], $dashboardUrlPrefix))
              ->add(CsrfMiddleware::class)
              ->add(AuthMiddleware::class);
        });
    }

    /**
     * Registers error handlers: Whoops for dev mode, Twig error templates for production.
     *
     * Template lookup hierarchy (Symfony-style):
     *   errors/404.twig → errors/4xx.twig → errors/error.twig
     *
     * All exceptions are logged to the error channel before rendering.
     *
     * @param App                $app
     * @param ContainerInterface $container PSR-11 container.
     * @param bool               $isDev
     */
    private static function registerErrorHandlers(App $app, ContainerInterface $container, bool $isDev): void
    {
        $errorMiddleware = $app->addErrorMiddleware($isDev, true, true);

        if ($isDev && class_exists(\Whoops\Run::class)) {
            $prettyHandler = new \Whoops\Handler\PrettyPageHandler();
            $sensitivePattern = '/(SECRET|TOKEN|PASSWORD|PASSWD|PWD|_PASS|_KEY|_HASH|_SALT|_PEM|_KEK|CREDENTIAL|API[_-]?KEY)/i';
            foreach (\array_keys(\array_merge($_ENV, $_SERVER)) as $envKey) {
                if (\preg_match($sensitivePattern, (string) $envKey)) {
                    $prettyHandler->blacklist('_ENV', $envKey);
                    $prettyHandler->blacklist('_SERVER', $envKey);
                }
            }

            $whoops = new \Whoops\Run();
            $whoops->allowQuit(false);
            $whoops->writeToOutput(false);
            $whoops->pushHandler($prettyHandler);

            $errorMiddleware->setDefaultErrorHandler(
                new SafeErrorHandler(
                    $app->getResponseFactory(),
                    $container->get('logger.error'),
                    null,
                    $whoops
                )
            );
            return;
        }

        $errorMiddleware->setDefaultErrorHandler(
            new SafeErrorHandler(
                $app->getResponseFactory(),
                $container->get('logger.error'),
                function (int $status, string $message, bool $showDetails, ServerRequestInterface $request) use ($container): ?ResponseInterface {
                    $response  = new Response($status);
                    $category  = \intdiv($status, 100) . 'xx';
                    $templates = ["errors/{$status}.twig", "errors/{$category}.twig", 'errors/error.twig'];
                    try {
                        $view = $container->get('view');
                        foreach ($templates as $template) {
                            try {
                                return $view->render($response, $template, ['status' => $status, 'message' => $message]);
                            } catch (Throwable) {
                                // try next template in hierarchy
                            }
                        }
                    } catch (Throwable) {
                        // fall through to SafeErrorHandler plain-text fallback
                    }
                    return null;
                }
            )
        );
    }

    /**
     * Registers multiple named routes for a single module.
     *
     * Use the routes key for modules that need full CRUD (list, new, edit, delete)
     * instead of a single render/handle pair.
     *
     * Route definition format:
     *   'METHOD /path' => callable
     *
     * Handler signature:
     *   (ServerRequestInterface, ResponseInterface, array $args, Twig, PDO, ContainerInterface): ResponseInterface
     *
     * The $module array must have 'slug' (used as the route prefix) and 'routes'.
     * Callers decide the target group: admin modules call this on the admin group,
     * module settings call this on the /settings group — the method is group-agnostic.
     *
     * Auth and CSRF are applied at the group level, not per-route.
     *
     * @param App|RouteCollectorProxy $target
     * @param array<string, mixed>    $module
     * @param ContainerInterface      $container PSR-11 container.
     */
    private static function addRoutesMap(mixed $target, array $module, ContainerInterface $container): void
    {
        $prefix = '/' . $module['slug'];
        foreach ($module['routes'] as $endpoint => $handler) {
            [$method, $path] = explode(' ', $endpoint, 2);
            $fullPath = $prefix . ($path === '/' ? '' : $path);
            $target->map(
                [strtoupper($method)],
                $fullPath,
                function ($req, $res, $args) use ($handler, $container) {
                    $view = $container->get('view');
                    $db   = $container->get(PDO::class);
                    return $handler($req, $res, $args, $view, $db, $container);
                }
            );
        }
    }

    /**
     * Registers GET and POST routes for a single module.
     *
     * Module callback signatures (5th arg is PSR-11, optional):
     *   render: (ServerRequestInterface, ResponseInterface, Twig, PDO, ContainerInterface): ResponseInterface
     *   handle: (ServerRequestInterface, ResponseInterface, Twig, PDO, ContainerInterface): ResponseInterface
     *
     * @param App|RouteCollectorProxy $target
     * @param array<string, mixed>    $module
     * @param ContainerInterface      $container PSR-11 container.
     */
    private static function addModuleRoute(mixed $target, array $module, ContainerInterface $container): void
    {
        $slug    = $module['slug'];
        $methods = [];

        if (isset($module['render'])) {
            $methods[] = 'GET';
        }
        if (isset($module['handle'])) {
            $methods[] = 'POST';
        }

        if (empty($methods)) {
            return;
        }

        $path = $module['path'] ?? '/' . $slug;

        $target->map(
            $methods,
            $path,
            function ($req, $res, $args) use ($module, $container) {
                $view = $container->get('view');
                $db   = $container->get(PDO::class);

                if ($req->getMethod() === 'POST' && isset($module['handle'])) {
                    return ($module['handle'])($req, $res, $view, $db, $container);
                }

                return ($module['render'])($req, $res, $view, $db, $container);
            }
        );
    }
}
