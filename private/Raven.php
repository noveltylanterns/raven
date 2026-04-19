<?php

/**
 * RAVEN CMS
 * ~/private/Raven.php
 * Shared Raven bootstrap container and service wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven;

use PDO;
use Raven\Core\Config;
use Raven\Core\Database\ConnectionFactory;
use Raven\Core\Database\Schema\SchemaManager;
use Raven\Core\Logger;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\SessionCookie;
use Raven\Lib\Config\ConfigParser;
use Raven\Lib\Extension\ExtensionRegistry;
use Raven\Lib\Scheduler\Registry;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared Raven bootstrap entrypoint.
 */
final class Raven
{
    /**
     * Builds and returns the shared Raven service container.
     *
     * @return array<string, mixed> Shared Raven bootstrap container.
     */
    public static function boot(): array
    {
        return (static function (): array {
    $root = dirname(__DIR__);
    require_once $root . '/private/lib/Extension/ExtensionRegistry.php';
    require_once $root . '/private/lib/Extension/Layout.php';
    $enabledExtensionDirectories = ExtensionRegistry::enabledDirectories($root, true);

    // Load per-package handlers instead of the full Composer autoloader.
    // Each handler registers a targeted PSR-4 autoloader for its package only.
    // 2FA/WebAuthn/QR handlers are loaded on-demand in the controllers that need them.
    $composerHandlerDir = $root . '/private/lib/Composer';
    $authHandler = $composerHandlerDir . '/delight-im/auth.php';
    if (is_file($authHandler)) {
        require_once $authHandler;
    }

    // Always provide local PSR-4 fallback.
    // Someone will probably want to build hooks with it.
    // Also lets app/lib/extension classes work before install.
    spl_autoload_register(static function (string $class) use ($root, $enabledExtensionDirectories): void {
        $libPrefix = 'Raven\\Lib\\';
        if (str_starts_with($class, $libPrefix)) {
            $relativeLib = str_replace('\\', '/', substr($class, strlen($libPrefix)));
            $libFile = $root . '/private/lib/' . $relativeLib . '.php';
            if (is_file($libFile)) {
                require_once $libFile;
            }
            return;
        }

        // Core sys/ classes: Raven\Core\* maps to private/sys/.
        $corePrefix = 'Raven\\Core\\';
        if (str_starts_with($class, $corePrefix)) {
            $relativeSys = str_replace('\\', '/', substr($class, strlen($corePrefix)));
            $sysFile = $root . '/private/sys/' . $relativeSys . '.php';
            if (is_file($sysFile)) {
                require_once $sysFile;
            }
            return;
        }

        // Extension classes now resolve from each extension's canonical `lib/`
        // namespace root, with `src/` retained as a legacy migration fallback.
        $extPrefix = 'Raven\\Ext\\';
        if (!str_starts_with($class, $extPrefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($extPrefix)));
        foreach ($enabledExtensionDirectories as $directory) {
            $extensionRoot = $root . '/private/ext/' . $directory;
            foreach (\Raven\Lib\Extension\Layout::classRoots($extensionRoot) as $classRoot) {
                if (!is_dir($classRoot)) {
                    continue;
                }

                $file = $classRoot . '/' . $relative . '.php';
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    });

    // Load global helper functions.
    require_once $root . '/private/lib/Extra/Helpers.php';

    $config = new Config($root . '/private/dat/config.php');

    // Initialize session early for auth, CSRF, and flash messaging.
    $sessionCookie = new SessionCookie();
    $sessionCookie->startIfNeeded($config, $root, $_SERVER);

    $databaseConfig = (array) $config->get('database', []);
    $connectionFactory = new ConnectionFactory($databaseConfig);

    $driver = $connectionFactory->getDriver();
    $prefix = $connectionFactory->getPrefix();

    $rvnDb = $connectionFactory->createAppConnection();

    // Keep app-side schema current during every bootstrap, but defer auth-side
    // schema and auth connection setup until something actually needs auth.
    $schema = new SchemaManager();
    $schema->ensureApp($rvnDb, $driver, $prefix);

    $authDb = null;
    $authDbResolver = static function () use (&$authDb, $connectionFactory, $schema, $driver, $prefix): PDO {
        if ($authDb instanceof PDO) {
            return $authDb;
        }

        $authDb = $connectionFactory->createAuthConnection();
        $schema->ensureAuth($authDb, $driver, $prefix);
        return $authDb;
    };

    $auth = null;
    $authResolver = static function () use (&$auth, $authDbResolver, $rvnDb, $driver, $prefix): AuthService {
        if ($auth instanceof AuthService) {
            return $auth;
        }

        $auth = new AuthService($authDbResolver(), $rvnDb, $driver, $prefix);
        return $auth;
    };

    $input = new InputSanitizer();
    $categoryEnabled = ConfigParser::bool($config->get('category.enabled', false), false);
    $tagEnabled = ConfigParser::bool($config->get('tag.enabled', false), false);
    $loggingConfig = (array) $config->get('logging', []);
    $logger = null;
    $loggerResolver = static function () use (&$logger, $rvnDb, $driver, $prefix, $loggingConfig): Logger {
        if (!$logger instanceof Logger) {
            // Keep logger wiring local to core bootstrap internals so rare panel/log
            // consumers do not expand the shared repo service contract.
            $logger = new Logger($rvnDb, $driver, $prefix, $loggingConfig);
        }

        return $logger;
    };

    // Hook into PHP's error handler to capture runtime errors/warnings into the event log.
    // Returns false so PHP's own default handler still runs (stderr/error_log output continues).
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use ($loggerResolver): bool {
        if (!($errno & error_reporting())) {
            // Error is suppressed by the current error_reporting level — skip it.
            return false;
        }

        $severity = in_array($errno, [E_ERROR, E_USER_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            ? 'error'
            : 'warn';

        try {
            $loggerResolver()->log($severity, $errstr, 'system', [
                'errno' => $errno,
                'file'  => $errfile,
                'line'  => $errline,
            ]);
        } catch (\Throwable) {
            // Logger failures must never cascade into another error handler call.
        }

        return false;
    });

    $extensionRegistry = new ExtensionRegistry($root, $enabledExtensionDirectories);
    $extensionManifests = $extensionRegistry->manifests();
    $extensionStorage = $extensionRegistry->storageMap();
    $schedulerExtensions = $extensionRegistry->schedulerDirectories();

    $rvn = [
        'root' => $root,
        'config' => $config,
        'driver' => $driver,
        'prefix' => $prefix,
        'db' => $rvnDb,
        'auth_db' => $authDbResolver,
        'auth' => $authResolver,
        'input' => $input,
        'csrf' => new Csrf(),
        'enabled_extension_directories' => array_keys($extensionManifests),
        'enabled_extension_manifests' => $extensionManifests,
        'extension_storage' => $extensionStorage,
        'extension_services' => [],
    ];

    $rvn['boot_extension'] = static function (string $directory) use (&$rvn, $extensionRegistry): array {
        return $extensionRegistry->bootExtension($rvn, $directory);
    };

    /**
     * Resolves one extension's service map on demand without booting unrelated extensions.
     *
     * @return array<string, mixed>
     */
    $rvn['extension_services_for'] = static function (string $directory) use (&$rvn, $extensionRegistry): array {
        return $extensionRegistry->resolveExtensionServices($rvn, $directory);
    };

    /**
     * Resolves all extension service maps, booting each extension only when needed.
     *
     * @return array<string, array<string, mixed>>
     */
    $rvn['extension_services_all'] = static function () use (&$rvn, $extensionRegistry): array {
        return $extensionRegistry->resolveAllExtensionServices($rvn);
    };

    $extensionsBooted = false;
    $rvn['boot_extensions'] = static function () use (&$extensionsBooted, &$rvn, $extensionRegistry): array {
        if ($extensionsBooted) {
            return $rvn;
        }

        $extensionsBooted = true;
        return $extensionRegistry->bootAllExtensions($rvn);
    };

    // Wire the system-wide scheduler.
    // Both core jobs and extension-declared jobs (from extension `cron.php`) run through this registry.
    // The scheduler is passive at bootstrap time — jobs only execute when rvn-cron triggers runDue().
    $scheduler = new Registry($root);

    // Built-in core job: flip page publish/draft status based on scheduled publish/expires columns.
    // Interval of 60 s means rvn-cron running every minute covers all scheduled posts promptly.
    // The fallback scheduler in public/index.php and panel/index.php fires runDue() non-blocking
    // after each response (throttled to 60 s) when site.scheduler is true, so this job runs on
    // request traffic without a server crontab. Operators may disable site.scheduler and point
    // their own crontab at rvn-cron instead.
    $scheduler->registerJob('core', 'page-schedule', 60, static function () use ($rvnDb, $driver, $prefix, $root, $categoryEnabled, $tagEnabled): void {
        // Build repos directly here — the shared bootstrap service map was removed,
        // so scheduler jobs own their own lightweight repo construction.
        $channelRepo = new ChannelRepository($rvnDb, $driver, $prefix, $root . '/private/dat/channel');
        $pageRepo = new PageRepository($rvnDb, $driver, $prefix, $channelRepo, $categoryEnabled, $tagEnabled);
        $pageRepo->applySchedule();
    });

    // Built-in core job: prune event log entries older than the configured retention period.
    // Runs once per day (86400 s). Pruning is deferred to a job so the logger's hot path stays fast.
    $scheduler->registerJob('core', 'event-log-prune', 86400, static function () use ($loggerResolver): void {
        $logger = $loggerResolver();
        $logger->pruneOlderThan($logger->retentionDays());
    });

    // Register extension sources; extension cron providers are loaded lazily on first runDue()/getStatus().
    foreach ($schedulerExtensions as $schedulerDirectory) {
        $scheduler->addExtensionSource($schedulerDirectory);
    }

    $rvn['scheduler'] = $scheduler;

    return $rvn;
        })();
    }
}
