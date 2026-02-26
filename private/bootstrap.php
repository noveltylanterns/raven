<?php

/**
 * RAVEN CMS
 * ~/private/bootstrap.php
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
use Raven\Core\Security\Csrf;
use Raven\Core\Security\InputSanitizer;
use Raven\Core\View;
use Raven\Repository\CategoryRepository;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomyRepository;
use Raven\Repository\UserRepository;

/**
 * Shared bootstrap for both web roots.
 *
 * Returns service container array used by front controllers.
 */
return (static function (): array {
    $root = dirname(__DIR__);
    require_once $root . '/private/src/Core/Extension/ExtensionRegistry.php';
    $enabledExtensionDirectories = ExtensionRegistry::enabledDirectories($root, true);

    // Load Composer autoloader when dependencies are installed.
    $composerAutoload = $root . '/composer/autoload.php';
    if (is_file($composerAutoload)) {
        require_once $composerAutoload;
    }

    // Always provide local PSR-4 fallback so app/extension classes work before install.
    spl_autoload_register(static function (string $class) use ($root, $enabledExtensionDirectories): void {
        $prefix = 'Raven\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $baseSpecs = [
            ['path' => $root . '/private/src/', 'flatten_repository' => false],
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
    require_once $root . '/private/src/Core/Support/Helpers.php';

    $config = new Config($root . '/private/config.php');

    // Initialize session early for auth, CSRF, and flash messaging.
    $sessionName = trim((string) $config->get('session.name', 'session'));
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
        $sessionName = 'session';
    }

    $cookiePrefix = trim((string) $config->get('session.cookie_prefix', 'rvn_'));
    if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) === 1) {
        $prefixedSessionName = $cookiePrefix . $sessionName;
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $prefixedSessionName) === 1) {
            $sessionName = $prefixedSessionName;
        }
    }

    $cookieDomain = strtolower(trim((string) $config->get('session.cookie_domain', '')));
    if (
        $cookieDomain !== ''
        && (
            preg_match('/[:\/\s]/', $cookieDomain) === 1
            || preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain) !== 1
        )
    ) {
        $cookieDomain = '';
    }

    // Guard against domain-move lockouts: if configured cookie domain does not
    // match the current request host, fall back to host-only cookies.
    $requestHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($requestHost !== '') {
        // Proxies may provide a host list; keep only the first value.
        if (str_contains($requestHost, ',')) {
            $requestHost = trim((string) explode(',', $requestHost, 2)[0]);
        }

        if (str_starts_with($requestHost, '[')) {
            // IPv6 literal host format: [addr]:port
            $closingBracketPos = strpos($requestHost, ']');
            if ($closingBracketPos !== false) {
                $requestHost = substr($requestHost, 1, $closingBracketPos - 1);
            }
        } else {
            // Strip :port from host:port while preserving raw IPv6 addresses.
            $lastColonPos = strrpos($requestHost, ':');
            if ($lastColonPos !== false && substr_count($requestHost, ':') === 1) {
                $maybePort = substr($requestHost, $lastColonPos + 1);
                if ($maybePort !== '' && ctype_digit($maybePort)) {
                    $requestHost = substr($requestHost, 0, $lastColonPos);
                }
            }
        }

        $requestHost = rtrim($requestHost, '.');
    }

    if ($cookieDomain !== '' && $requestHost !== '') {
        $cookieDomainForMatch = ltrim($cookieDomain, '.');
        $hostMatchesCookieDomain = $requestHost === $cookieDomainForMatch
            || str_ends_with($requestHost, '.' . $cookieDomainForMatch);
        if (!$hostMatchesCookieDomain) {
            $cookieDomain = '';
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Keep session files in project-private storage outside web roots.
        $sessionPath = $root . '/private/tmp/sessions';
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0775, true);
        }

        ini_set('session.save_path', $sessionPath);
        // Strict mode rejects uninitialized session IDs and reduces fixation risk.
        ini_set('session.use_strict_mode', '1');

        // Harden session cookies while staying compatible with local HTTP development.
        $httpsValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $isHttps = ($httpsValue !== '' && $httpsValue !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => (int) ($cookieParams['lifetime'] ?? 0),
            'path' => (string) ($cookieParams['path'] ?? '/'),
            'domain' => $cookieDomain !== '' ? $cookieDomain : (string) ($cookieParams['domain'] ?? ''),
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name($sessionName);
        session_start();
    }

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
    $app = [
        'root' => $root,
        'config' => $config,
        'driver' => $driver,
        'prefix' => $prefix,
        'db' => $appDb,
        'auth_db' => $authDb,
        'auth' => $auth,
        'view' => new View($root . '/private/views'),
        'input' => $input,
        'csrf' => new Csrf(),
        'categories' => new CategoryRepository($appDb, $driver, $prefix),
        'channels' => new ChannelRepository($appDb, $driver, $prefix),
        'groups' => new GroupRepository($appDb, $driver, $prefix),
        'page_images' => $pageImages,
        'page_image_manager' => new PageImageManager($config, $input, $pageImages, $root),
        'pages' => new PageRepository($appDb, $driver, $prefix),
        'redirects' => new RedirectRepository($appDb, $driver, $prefix),
        'tags' => new TagRepository($appDb, $driver, $prefix),
        'taxonomy' => new TaxonomyRepository($appDb, $driver, $prefix),
        'users' => new UserRepository($authDb, $appDb, $driver, $prefix),
    ];

    // Load service providers from enabled extensions.
    foreach ($enabledExtensionDirectories as $directory) {
        $extensionBootstrapPath = $root . '/private/ext/' . $directory . '/bootstrap.php';
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
