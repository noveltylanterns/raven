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
use Raven\Core\Runtime\DatabaseFactory;
use Raven\Core\Schema\SchemaManager;
use Raven\Core\Logger;
use Raven\Core\Postmaster;
use Raven\Lib\Parser\PageRepoParser;
use Raven\Core\Gatekeeper;
use Raven\Lib\Auth\SessionCookie;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Extension\Registry;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Scheduler\Registry as SchedulerRegistry;
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
        // IIFE creates an isolated local scope so bootstrap variables do not
        // pollute the calling script's scope when Raven::boot() is called at
        // the top of panel/index.php or public/index.php.
        return (static function (): array {
    $root = dirname(__DIR__);
    // These three files are required before the spl_autoload_register below
    // because enabledDirectories() needs them immediately at call time.
    require_once $root . '/private/lib/Extension/Registry.php';
    require_once $root . '/private/lib/Extension/Resolver.php';
    // StateRead instantiates StateWrite in its constructor,
    // which runs inside enabledDirectories() before the spl_autoload_register below.
    // Require it directly so it is available at that point.
    require_once $root . '/private/lib/Extension/StateWrite.php';
    $enabledExtensionDirectories = Registry::enabledDirectories($root, true);
    $enabledExtensionClassRoots = [];
    foreach ($enabledExtensionDirectories as $directory) {
        $extensionRoot = $root . '/private/ext/' . $directory;
        foreach (Resolver::classRoots($extensionRoot) as $classRoot) {
            if (is_dir($classRoot)) {
                $enabledExtensionClassRoots[] = rtrim($classRoot, '/\\');
            }
        }
    }

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
    spl_autoload_register(static function (string $class) use ($root, $enabledExtensionClassRoots): void {
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

        // Extension classes resolve only from each extension's canonical `lib/`
        // namespace root so scaffolded and third-party packages target one layout.
        $extPrefix = 'Raven\\Ext\\';
        if (!str_starts_with($class, $extPrefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($extPrefix)));
        static $extClassFileCache = [];
        if (array_key_exists($relative, $extClassFileCache)) {
            $cached = $extClassFileCache[$relative];
            if (is_string($cached) && $cached !== '') {
                require_once $cached;
            }

            return;
        }

        foreach ($enabledExtensionClassRoots as $classRoot) {
            $file = $classRoot . '/' . $relative . '.php';
            if (is_file($file)) {
                $extClassFileCache[$relative] = $file;
                require_once $file;
                return;
            }
        }

        // Cache misses so repeated unresolved extension classes do not re-scan all roots.
        $extClassFileCache[$relative] = false;
    });

    // Load namespaced helper functions that PHP's autoloader cannot discover.
    require_once $root . '/private/lib/Security/OutputEncoder.php';

    $config = new Config($root . '/private/dat/config.php');

    // Initialize session early for auth, CSRF, and flash messaging.
    $sessionCookie = new SessionCookie();
    $sessionCookie->startIfNeeded($config, $root, $_SERVER);

    $databaseConfig = (array) $config->get('database', []);
    $connectionFactory = new DatabaseFactory($databaseConfig);

    $driver = $connectionFactory->getDriver();
    $prefix = $connectionFactory->getPrefix();

    $rvnDb = $connectionFactory->createAppConnection();

    // Keep app-side schema current during every bootstrap, but defer auth-side
    // schema and auth connection setup until something actually needs auth.
    $schema = new SchemaManager();
    $schema->ensureApp($rvnDb, $driver, $prefix);

    // Auth DB and Gatekeeper are lazy: many public routes (anonymous pages, feed,
    // category listings) never touch auth at all. Deferring the auth connection and
    // schema-ensure avoids a full DB open + migration check on every request that
    // has nothing to do with login or sessions.
    $authDb = null;
    $authDbResolver = static function () use (&$authDb, $connectionFactory, $schema, $driver, $prefix): PDO {
        // Singleton guard: reuse the already-opened connection within one request
        // rather than opening a second PDO handle.
        if ($authDb instanceof PDO) {
            return $authDb;
        }

        $authDb = $connectionFactory->createAuthConnection();
        $schema->ensureAuth($authDb, $driver, $prefix);
        return $authDb;
    };

    $auth = null;
    $authResolver = static function () use (&$auth, $authDbResolver, $rvnDb, $driver, $prefix): Gatekeeper {
        // Same singleton guard: the auth service wraps the delight-im Auth object
        // which itself keeps session state, so only one instance per request is safe.
        if ($auth instanceof Gatekeeper) {
            return $auth;
        }

        $auth = new Gatekeeper($authDbResolver(), $rvnDb, $driver, $prefix);
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

    $extensionRegistry = new Registry($root, $enabledExtensionDirectories);
    $extensionManifests = $extensionRegistry->manifests();
    $extensionStorage = $extensionRegistry->storageMap();
    $schedulerExtensions = $extensionRegistry->schedulerDirectories();

    // The $rvn container is the shared service bag threaded through controllers,
    // builders, and extension boot functions.
    // Keys: root/config/driver/prefix/db are plain values resolved at boot time.
    // auth_db/auth are Closures — callers must check is_callable() before use;
    //   calling them opens the auth DB and runs schema-ensure on first access.
    // extension_services starts empty; boot_extension/extension_services_for/
    //   extension_services_all populate it lazily per extension.
    $rvn = [
        'root' => $root,
        'config' => $config,
        'postmaster' => new Postmaster($config),
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
        // Populated on first access per extension via the closures below.
        'extension_services' => [],
    ];

    /**
     * Boots one extension by directory slug and returns the updated $rvn container.
     *
     * @param string $directory Extension directory slug to boot.
     * @return array<string, mixed> Updated service container after extension boot.
     */
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
    /**
     * Boots all enabled extensions in one pass and returns the updated $rvn container.
     *
     * Idempotent: subsequent calls return the already-booted container without
     * re-running extension boot functions.
     *
     * @return array<string, mixed> Updated service container after all extensions boot.
     */
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
    $scheduler = new SchedulerRegistry($root);

    // Built-in core job: flip page publish/draft status based on scheduled publish/expires columns.
    // Interval of 60 s means rvn-cron running every minute covers all scheduled posts promptly.
    // The fallback scheduler in public/index.php and panel/index.php fires runDue() non-blocking
    // after each response (throttled to 60 s) when site.scheduler is true, so this job runs on
    // request traffic without a server crontab. Operators may disable site.scheduler and point
    // their own crontab at rvn-cron instead.
    $scheduler->registerJob('core', 'page-schedule', 60, static function () use ($rvnDb, $driver, $prefix, $root, $categoryEnabled, $tagEnabled): void {
        // PageRepoParser::applySchedule() runs the schedule flip without loading PageRead or PageWrite,
        // keeping this hot-path job lean regardless of which repos are in scope at bootstrap time.
        PageRepoParser::applySchedule($rvnDb, $driver, $prefix);
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
