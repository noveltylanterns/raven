<?php

/**
 * RAVEN CMS
 * ~/private/raven.php
 * Application bootstrap wiring config and services.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Auth\AuthService;
use Raven\Core\Config;
use Raven\Core\Database\ConnectionFactory;
use Raven\Core\Database\SchemaManager;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Core\Media\PageImageManager;
use Raven\Core\View;
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Extension\ExtensionBootstrapContractResolver;
use Raven\Lib\Log\EventLogger;
use Raven\Lib\Scheduler\SchedulerRegistry;
use Raven\Lib\Session\SessionCookiePolicy;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Repository\CategoryRepository;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomySetRepository;
use Raven\Repository\TaxonomyLookupRepository;
use Raven\Repository\UserRepository;

/**
 * Shared bootstrap for both web roots.
 *
 * Returns service container array used by front controllers.
 */
return (static function (): array {
    $root = dirname(__DIR__);
    require_once $root . '/private/sys/Core/Extension/ExtensionRegistry.php';
    $enabledExtensionDirectories = ExtensionRegistry::enabledDirectories($root, true);

    // Load per-package handlers instead of the full Composer autoloader.
    // Each handler registers a targeted PSR-4 autoloader for its package only.
    // 2FA/WebAuthn/QR handlers are loaded on-demand in the controllers that need them.
    $composerHandlerDir = $root . '/private/lib/Composer';
    $authHandler = $composerHandlerDir . '/delight-im/auth.php';
    if (is_file($authHandler)) {
        require_once $authHandler;
    }

    // Always provide local PSR-4 fallback so app/lib/extension classes work before install.
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

        $prefix = 'Raven\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $baseSpecs = [
            ['path' => $root . '/private/sys/', 'flatten_repository' => false],
        ];
        foreach ($enabledExtensionDirectories as $directory) {
            $extensionSourcePath = $root . '/private/ext/' . $directory . '/src/';
            if (!is_dir($extensionSourcePath)) {
                continue;
            }

            $baseSpecs[] = ['path' => $extensionSourcePath, 'flatten_repository' => true];
        }

        foreach ($baseSpecs as $baseSpec) {
            $base = (string) ($baseSpec['path'] ?? '');
            $flattenRepository = !empty($baseSpec['flatten_repository']);
            $candidates = [$base . $relative . '.php'];

            if ($flattenRepository && str_starts_with($relative, 'Repository/')) {
                $candidates[] = $base . substr($relative, strlen('Repository/')) . '.php';
            }

            foreach ($candidates as $file) {
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    });

    // Load global helper functions.
    require_once $root . '/private/sys/Core/Support/Helpers.php';

    $config = new Config($root . '/private/dat/config.php');

    // Initialize session early for auth, CSRF, and flash messaging.
    $sessionCookiePolicy = new SessionCookiePolicy();
    $sessionCookiePolicy->startIfNeeded($config, $root, $_SERVER);

    $databaseConfig = (array) $config->get('database', []);
    $connectionFactory = new ConnectionFactory($databaseConfig);

    $driver = $connectionFactory->getDriver();
    $prefix = $connectionFactory->getPrefix();

    $rvnDb = $connectionFactory->createAppConnection();
    $authDb = $connectionFactory->createAuthConnection();

    // Ensure schema exists on each startup to keep first run friction low.
    $schema = new SchemaManager();
    $schema->ensure($rvnDb, $authDb, $driver, $prefix);

    $auth = new AuthService($authDb, $rvnDb, $driver, $prefix);

    $input = new InputSanitizer();
    $categoryEnabled = ConfigValueParser::bool($config->get('category.enabled', false), false);
    $tagEnabled = ConfigValueParser::bool($config->get('tag.enabled', false), false);
    $loggingConfig = (array) $config->get('logging', []);
    $extensionBootstrapResolver = new ExtensionBootstrapContractResolver();

    $serviceCache = [];
    $serviceFactories = [
        'category' => static fn (): CategoryRepository => new CategoryRepository($rvnDb, $driver, $prefix),
        'category_set' => static fn (): TaxonomySetRepository => new TaxonomySetRepository('category', $root . '/private/dat/category-set'),
        'channel' => static fn (): ChannelRepository => new ChannelRepository($rvnDb, $driver, $prefix, $root . '/private/dat/channel'),
        'group' => static fn (): GroupRepository => new GroupRepository($rvnDb, $driver, $prefix),
        'invite_tokens' => static fn (): InviteTokenRepository => new InviteTokenRepository($authDb, $driver, $prefix),
        'page_images' => static fn (): PageImageRepository => new PageImageRepository($rvnDb, $driver, $prefix),
        'page_image_manager' => static function () use ($config, $input, &$serviceFactories, &$serviceCache, $root): PageImageManager {
            if (!isset($serviceCache['page_images'])) {
                $serviceCache['page_images'] = $serviceFactories['page_images']();
            }

            /** @var PageImageRepository $pageImages */
            $pageImages = $serviceCache['page_images'];
            return new PageImageManager($config, $input, $pageImages, $root);
        },
        'page' => static function () use ($rvnDb, $driver, $prefix, $categoryEnabled, $tagEnabled, &$serviceFactories, &$serviceCache): PageRepository {
            if (!isset($serviceCache['channel'])) {
                $serviceCache['channel'] = $serviceFactories['channel']();
            }

            /** @var ChannelRepository $channelRepo */
            $channelRepo = $serviceCache['channel'];
            return new PageRepository($rvnDb, $driver, $prefix, $channelRepo, $categoryEnabled, $tagEnabled);
        },
        'redirect' => static function () use ($rvnDb, $driver, $prefix, &$serviceFactories, &$serviceCache): RedirectRepository {
            if (!isset($serviceCache['channel'])) {
                $serviceCache['channel'] = $serviceFactories['channel']();
            }

            /** @var ChannelRepository $channelRepo */
            $channelRepo = $serviceCache['channel'];
            return new RedirectRepository($rvnDb, $driver, $prefix, $channelRepo);
        },
        'tag' => static fn (): TagRepository => new TagRepository($rvnDb, $driver, $prefix),
        'tag_set' => static fn (): TaxonomySetRepository => new TaxonomySetRepository('tag', $root . '/private/dat/tag-set'),
        'taxonomy_lookup' => static function () use ($rvnDb, $driver, $prefix, &$serviceFactories, &$serviceCache): TaxonomyLookupRepository {
            if (!isset($serviceCache['channel'])) {
                $serviceCache['channel'] = $serviceFactories['channel']();
            }

            /** @var ChannelRepository $channelRepo */
            $channelRepo = $serviceCache['channel'];
            return new TaxonomyLookupRepository($rvnDb, $driver, $prefix, $channelRepo);
        },
        'user' => static fn (): UserRepository => new UserRepository($authDb, $rvnDb, $driver, $prefix),
        // The logger is lazy so quiet requests do not pay for log-repository wiring.
        'logger' => static fn (): EventLogger => new EventLogger($rvnDb, $driver, $prefix, $loggingConfig),
    ];
    $service = static function (string $name) use (&$serviceCache, $serviceFactories): mixed {
        if (!array_key_exists($name, $serviceFactories)) {
            throw new InvalidArgumentException('Unknown Raven bootstrap service "' . $name . '".');
        }

        if (!array_key_exists($name, $serviceCache)) {
            $serviceCache[$name] = $serviceFactories[$name]();
        }

        return $serviceCache[$name];
    };

    // Hook into PHP's error handler to capture runtime errors/warnings into the event log.
    // Returns false so PHP's own default handler still runs (stderr/error_log output continues).
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use ($service): bool {
        if (!($errno & error_reporting())) {
            // Error is suppressed by the current error_reporting level — skip it.
            return false;
        }

        $severity = in_array($errno, [E_ERROR, E_USER_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            ? 'error'
            : 'warn';

        try {
            /** @var EventLogger $logger */
            $logger = $service('logger');
            $logger->log($severity, $errstr, 'system', [
                'errno' => $errno,
                'file'  => $errfile,
                'line'  => $errline,
            ]);
        } catch (\Throwable) {
            // Logger failures must never cascade into another error handler call.
        }

        return false;
    });

    $extensionManifests = [];
    $extensionStorage = [];
    $extensionBootProviders = [];
    $schedulerExtensions = [];
    foreach ($enabledExtensionDirectories as $directory) {
        $manifest = ExtensionRegistry::readManifest($root, $directory);
        if (!is_array($manifest)) {
            continue;
        }

        $extensionManifests[$directory] = $manifest;

        $bootstrap = $extensionBootstrapResolver->resolve($root, $directory, $manifest);
        if (!$bootstrap['valid']) {
            error_log('Raven extension bootstrap is invalid for extension "' . $directory . '": ' . (string) ($bootstrap['error'] ?? 'Unknown error.'));
            continue;
        }

        if (!empty($bootstrap['scheduler'])) {
            $schedulerExtensions[] = $directory;
        }

        $storage = is_array($bootstrap['storage'] ?? null) ? (array) $bootstrap['storage'] : [];
        $extensionStorage[$directory] = [
            'local' => !empty($storage['local']) ? ($root . '/private/dat/ext/' . $directory) : '',
            'aux' => [],
            'panel' => !empty($storage['panel']) ? ($root . '/panel/ext/' . $directory) : '',
            'public' => !empty($storage['public']) ? ($root . '/public/uploads/ext/' . $directory) : '',
            // Bin storage resolves to the extension's own bin/ directory; private/bin/ contains the symlinks.
            'bin' => !empty($storage['bin']) ? ($root . '/private/ext/' . $directory . '/bin') : '',
        ];
        foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
            if (!is_string($auxDirectory) || $auxDirectory === '') {
                continue;
            }

            $extensionStorage[$directory]['aux'][$auxDirectory] = $root . '/' . $auxDirectory;
        }

        $provider = $bootstrap['boot'] ?? null;
        if (is_callable($provider)) {
            $extensionBootProviders[$directory] = $provider;
        }
    }

    $rvn = [
        'root' => $root,
        'config' => $config,
        'driver' => $driver,
        'prefix' => $prefix,
        'db' => $rvnDb,
        'auth_db' => $authDb,
        'auth' => $auth,
        'view' => new View($root . '/private/tpl'),
        'input' => $input,
        'csrf' => new Csrf(),
        'service' => $service,
        'enabled_extension_directories' => array_keys($extensionManifests),
        'enabled_extension_manifests' => $extensionManifests,
        'extension_storage' => $extensionStorage,
        'extension_services' => [],
    ];

    $bootedExtensionDirectories = [];
    $rvn['boot_extension'] = static function (string $directory) use (&$rvn, &$bootedExtensionDirectories, $extensionBootProviders): array {
        $directory = trim($directory);
        if ($directory === '' || isset($bootedExtensionDirectories[$directory])) {
            return $rvn;
        }

        $bootedExtensionDirectories[$directory] = true;
        $provider = $extensionBootProviders[$directory] ?? null;
        if (!is_callable($provider)) {
            return $rvn;
        }

        try {
            $provider($rvn);
        } catch (\Throwable $exception) {
            error_log('Raven extension bootstrap failed for extension "' . $directory . '": ' . $exception->getMessage());
        }

        return $rvn;
    };

    /**
     * Resolves one extension's service map on demand without booting unrelated extensions.
     *
     * @return array<string, mixed>
     */
    $rvn['extension_services_for'] = static function (string $directory) use (&$rvn): array {
        $directory = trim($directory);
        if ($directory === '') {
            return [];
        }

        if (is_callable($rvn['boot_extension'] ?? null)) {
            /** @var callable(string): array<string, mixed> $bootExtension */
            $bootExtension = $rvn['boot_extension'];
            $rvn = $bootExtension($directory);
        }

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        /** @var mixed $rawServices */
        $rawServices = is_array($rawExtensionServices) ? ($rawExtensionServices[$directory] ?? []) : [];
        return is_array($rawServices) ? $rawServices : [];
    };

    $extensionsBooted = false;
    $rvn['boot_extensions'] = static function () use (&$extensionsBooted, &$rvn, $extensionBootProviders): array {
        if ($extensionsBooted) {
            return $rvn;
        }

        $extensionsBooted = true;
        foreach (array_keys($extensionBootProviders) as $directory) {
            if (is_callable($rvn['boot_extension'] ?? null)) {
                /** @var callable(string): array<string, mixed> $bootExtension */
                $bootExtension = $rvn['boot_extension'];
                $rvn = $bootExtension($directory);
            }
        }

        return $rvn;
    };

    // Wire the system-wide scheduler.
    // Both core jobs and extension-declared jobs (from lib/cron.php) run through this registry.
    // The scheduler is passive at bootstrap time — jobs only execute when rvn-cron triggers runDue().
    $scheduler = new SchedulerRegistry($root);

    // Built-in core job: flip page publish/draft status based on scheduled publish/expires columns.
    // Interval of 60 s means rvn-cron running every minute covers all scheduled posts promptly.
    // The fallback scheduler in public/index.php and panel/index.php fires runDue() non-blocking
    // after each response (throttled to 60 s) when site.scheduler is true, so this job runs on
    // request traffic without a server crontab. Operators may disable site.scheduler and point
    // their own crontab at rvn-cron instead.
    $scheduler->registerJob('core', 'page-schedule', 60, static function () use ($service): void {
        /** @var PageRepository $page */
        $page = $service('page');
        $page->applySchedule();
    });

    // Built-in core job: prune event log entries older than the configured retention period.
    // Runs once per day (86400 s). Pruning is deferred to a job so the logger's hot path stays fast.
    $scheduler->registerJob('core', 'event-log-prune', 86400, static function () use ($service): void {
        /** @var EventLogger $logger */
        $logger = $service('logger');
        $logger->pruneOlderThan($logger->retentionDays());
    });

    // Register extension sources; lib/cron.php files are loaded lazily on first runDue()/getStatus().
    foreach ($schedulerExtensions as $schedulerDirectory) {
        $scheduler->addExtensionSource($schedulerDirectory);
    }

    $rvn['scheduler'] = $scheduler;

    return $rvn;
})();
