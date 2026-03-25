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
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\Session\SessionCookiePolicy;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Core\View;
use Raven\Repository\CategoryRepository;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
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

    // Load Composer autoloader when dependencies are installed.
    $composerAutoload = $root . '/composer/autoload.php';
    if (is_file($composerAutoload)) {
        // Prevent EasyMDE's Tualo Office PHP bridge from auto-loading on every request.
        // Raven only uses EasyMDE static assets on the page editor.
        $composerAutoloadFiles = $root . '/composer/composer/autoload_files.php';
        if (is_file($composerAutoloadFiles)) {
            $autoloadFiles = require $composerAutoloadFiles;
            if (is_array($autoloadFiles)) {
                if (!isset($GLOBALS['__composer_autoload_files']) || !is_array($GLOBALS['__composer_autoload_files'])) {
                    $GLOBALS['__composer_autoload_files'] = [];
                }

                foreach ($autoloadFiles as $fileIdentifier => $filePath) {
                    $normalizedPath = str_replace('\\', '/', (string) $filePath);
                    if (str_contains($normalizedPath, '/tualo/easymde/src/functions.php')) {
                        $GLOBALS['__composer_autoload_files'][(string) $fileIdentifier] = true;
                    }
                }
            }
        }

        require_once $composerAutoload;
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

    $appDb = $connectionFactory->createAppConnection();
    $authDb = $connectionFactory->createAuthConnection();

    // Ensure schema exists on each startup to keep first run friction low.
    $schema = new SchemaManager();
    $schema->ensure($appDb, $authDb, $driver, $prefix);

    $auth = new AuthService($authDb, $appDb, $driver, $prefix);

    $input = new InputSanitizer();
    $pageImages = new PageImageRepository($appDb, $driver, $prefix);
    $channelRepo = new ChannelRepository($appDb, $driver, $prefix, $root . '/private/dat/channel');
    $categoryEnabled = ConfigValueParser::bool($config->get('category.enabled', true), true);
    $tagEnabled = ConfigValueParser::bool($config->get('tag.enabled', true), true);
    $siteContextBuilder = new SiteContextBuilder();
    $app = [
        'root' => $root,
        'config' => $config,
        'driver' => $driver,
        'prefix' => $prefix,
        'db' => $appDb,
        'auth_db' => $authDb,
        'auth' => $auth,
        'view' => new View($root . '/private/tpl'),
        'input' => $input,
        'csrf' => new Csrf(),
        'panel_site_data' => static function (bool $includeDomain = true) use ($siteContextBuilder, $config, $categoryEnabled, $tagEnabled): array {
            return $siteContextBuilder->panel($config, $categoryEnabled, $tagEnabled, $includeDomain);
        },
        'category' => new CategoryRepository($appDb, $driver, $prefix),
        'channel' => $channelRepo,
        'group' => new GroupRepository($appDb, $driver, $prefix),
        'invite_tokens' => new InviteTokenRepository($authDb, $driver, $prefix),
        'page_images' => $pageImages,
        'page_image_manager' => new PageImageManager($config, $input, $pageImages, $root),
        'page' => new PageRepository($appDb, $driver, $prefix, $channelRepo, $categoryEnabled, $tagEnabled),
        'redirect' => new RedirectRepository($appDb, $driver, $prefix, $channelRepo),
        'tag' => new TagRepository($appDb, $driver, $prefix),
        'taxonomy_lookup' => new TaxonomyLookupRepository($appDb, $driver, $prefix, $channelRepo),
        'user' => new UserRepository($authDb, $appDb, $driver, $prefix),
    ];

    // Load service providers from enabled extensions.
    foreach ($enabledExtensionDirectories as $directory) {
        $extensionBootstrapPath = $root . '/private/ext/' . $directory . '/ext.php';
        if (!is_file($extensionBootstrapPath)) {
            continue;
        }

        /** @var mixed $provider */
        $provider = require $extensionBootstrapPath;
        if (!is_callable($provider)) {
            error_log('Raven extension bootstrap is invalid for extension "' . $directory . '".');
            continue;
        }

        try {
            $provider($app);
        } catch (\Throwable $exception) {
            error_log('Raven extension bootstrap failed for extension "' . $directory . '": ' . $exception->getMessage());
        }
    }

    return $app;
})();
