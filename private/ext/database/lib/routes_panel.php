<?php

/**
 * RAVEN CMS
 * ~/private/ext/database/lib/routes_panel.php
 * Database Manager extension panel route registration.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This file keeps Database Manager routing logic isolated from panel/index.php.

declare(strict_types=1);

use Raven\Core\Routing\Router;

/**
 * Registers Database Manager routes into the panel router.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string,
 *   renderPublicNotFound: callable(): void
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $rvn */
    $rvn = (array) ($context['rvn'] ?? []);

    /** @var callable(string): string $panelUrl */
    $panelUrl = $context['panelUrl'] ?? static fn (string $suffix = ''): string => '/' . ltrim($suffix, '/');

    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'default';

    /** @var callable(bool=): array<string, mixed> $panelSiteData */
    $panelSiteData = is_callable($rvn['panel_site_data'] ?? null)
        ? $rvn['panel_site_data']
        : static function (bool $includeDomain = true) use ($rvn): array {
            $site = [
                'name' => (string) $rvn['config']->get('site.name', 'Raven CMS'),
                'panel_path' => (string) $rvn['config']->get('panel.path', 'panel'),
                'panel_brand_name' => (string) $rvn['config']->get('panel.brand_name', ''),
                'panel_brand_logo' => (string) $rvn['config']->get('panel.brand_logo', ''),
            ];
            if ($includeDomain) {
                $site['domain'] = (string) $rvn['config']->get('site.domain', 'localhost');
            }
            return $site;
        };

    /** @var callable(): void $renderPublicNotFound */
    $renderPublicNotFound = $context['renderPublicNotFound'] ?? static function (): void {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
    };

    if (!isset($rvn['root'], $rvn['view'], $rvn['auth'], $rvn['config'], $rvn['csrf'])) {
        return;
    }

    $extensionRoot = rtrim((string) $rvn['root'], '/') . '/private/ext/database';
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $extensionEntrypoint = $extensionRoot . '/adminer.php';
    $extensionViewFile = $extensionRoot . '/tpl/panel_index.php';
    $extensionPublicRoot = rtrim((string) $rvn['root'], '/') . '/panel/ext/database';
    $extensionMeta = [
        'name' => 'Database Manager',
        'version' => '',
        'author' => '',
        'description' => '',
        'docs' => 'https://raven.lanterns.io',
    ];
    if (is_file($extensionManifestFile)) {
        $manifestRaw = file_get_contents($extensionManifestFile);
        if ($manifestRaw !== false && trim($manifestRaw) !== '') {
            /** @var mixed $manifestDecoded */
            $manifestDecoded = json_decode($manifestRaw, true);
            if (is_array($manifestDecoded)) {
                $manifestName = trim((string) ($manifestDecoded['name'] ?? ''));
                if ($manifestName !== '') {
                    $extensionMeta['name'] = $manifestName;
                }

                $extensionMeta['version'] = trim((string) ($manifestDecoded['version'] ?? ''));
                $extensionMeta['author'] = trim((string) ($manifestDecoded['author'] ?? ''));
                $extensionMeta['description'] = trim((string) ($manifestDecoded['description'] ?? ''));

                // "docs" is the canonical ext.json key for extension documentation links.
                $docsRaw = trim((string) ($manifestDecoded['docs'] ?? ''));
                if ($docsRaw !== '' && filter_var($docsRaw, FILTER_VALIDATE_URL) !== false) {
                    $docsScheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
                    if (in_array($docsScheme, ['http', 'https'], true)) {
                        $extensionMeta['docs'] = $docsRaw;
                    }
                }
            }
        }
    }

    /**
     * Resolves installed Adminer entrypoint from Composer package layout.
     */
    $resolveAdminerEntrypoint = static function () use ($rvn): ?string {
        $basePath = rtrim((string) $rvn['root'], '/') . '/composer/vrana/adminer';
        $candidates = [
            $basePath . '/adminer.php',
            $basePath . '/adminer/index.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    };

    /**
     * Resolves SQLite base path from config, allowing relative-to-root values.
     */
    $resolveSqliteBasePath = static function (array $databaseConfig) use ($rvn): string {
        $sqlite = (array) ($databaseConfig['sqlite'] ?? []);
        $basePath = trim((string) ($sqlite['path'] ?? ''));
        if ($basePath === '') {
            return rtrim((string) $rvn['root'], '/') . '/private/dat/db.sqlite';
        }

        $isAbsolute = str_starts_with($basePath, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $basePath) === 1;
        if ($isAbsolute) {
            return rtrim($basePath, '/');
        }

        return rtrim((string) $rvn['root'], '/') . '/' . ltrim($basePath, '/');
    };

    /**
     * Resolves the active SQLite database file path from config.
     */
    $resolveSqliteDatabasePath = static function (array $databaseConfig) use ($resolveSqliteBasePath): string {
        $basePath = $resolveSqliteBasePath($databaseConfig);
        if (preg_match('/\.(?:db|sqlite)$/i', $basePath) === 1) {
            return $basePath;
        }

        return rtrim($basePath, '/') . '/db.sqlite';
    };

    /**
     * Builds Adminer launch targets for the currently configured driver.
     *
     * @param array<string, mixed> $databaseConfig
     * @return array{
     *   driver: string,
     *   targets: array<int, array{name: string, detail: string, launch_path: string}>,
     *   selector_error: string|null
     * }
     */
    $buildAdminerLaunchTargets = static function (array $databaseConfig) use (
        $rvn,
        $panelUrl,
        $resolveSqliteDatabasePath
    ): array {
        $driver = strtolower((string) ($databaseConfig['driver'] ?? 'sqlite'));
        $targets = [];
        $selectorError = null;

        if ($driver === 'sqlite') {
            $databasePath = $resolveSqliteDatabasePath($databaseConfig);
            if (!is_file($databasePath)) {
                $selectorError = 'SQLite database file does not exist: ' . $databasePath;
            } else {
                $targets[] = [
                    'name' => basename($databasePath),
                    'detail' => 'Core + extension tables',
                    'launch_path' => $panelUrl('/database/adminer') . '?db=' . rawurlencode($databasePath),
                ];
            }
        } elseif (in_array($driver, ['mysql', 'pgsql'], true)) {
            try {
                $db = $rvn['db'] ?? null;
                if (!($db instanceof \PDO)) {
                    throw new RuntimeException('App DB connection is unavailable.');
                }

                if ($driver === 'mysql') {
                    $statement = $db->query(
                        "SELECT table_name
                         FROM information_schema.tables
                         WHERE table_schema = DATABASE()
                           AND table_type = 'BASE TABLE'"
                    );
                } else {
                    $statement = $db->query(
                        "SELECT table_name
                         FROM information_schema.tables
                         WHERE table_schema = current_schema()
                           AND table_type = 'BASE TABLE'"
                    );
                }

                $tableNames = $statement !== false ? $statement->fetchAll(\PDO::FETCH_COLUMN) : [];
                if (is_array($tableNames)) {
                    foreach ($tableNames as $tableNameRaw) {
                        $tableName = trim((string) $tableNameRaw);
                        if ($tableName === '') {
                            continue;
                        }

                        $targets[] = [
                            'name' => $tableName,
                            'detail' => '',
                            'launch_path' => $panelUrl('/database/adminer') . '?table=' . rawurlencode($tableName),
                        ];
                    }
                }
            } catch (\Throwable $throwable) {
                $selectorError = 'Failed to load SQL table list: ' . $throwable->getMessage();
            }
        } else {
            $selectorError = 'Unsupported database driver configured: ' . $driver;
        }

        usort(
            $targets,
            static function (array $left, array $right): int {
                return strtolower((string) ($left['name'] ?? '')) <=> strtolower((string) ($right['name'] ?? ''));
            }
        );

        return [
            'driver' => $driver,
            'targets' => $targets,
            'selector_error' => $selectorError,
        ];
    };

    /**
     * Renders one extension-owned panel template inside the shared panel layout.
     *
     * @param array<string, mixed> $viewData
     */
    $renderExtensionView = static function (array $viewData, ?string $viewFile = null) use ($rvn, $extensionViewFile, $currentUserTheme, $panelSiteData): void {
        $resolvedViewFile = is_string($viewFile) && trim($viewFile) !== ''
            ? $viewFile
            : $extensionViewFile;
        if (!is_file($resolvedViewFile)) {
            http_response_code(500);
            echo 'Database Manager view template is missing.';
            return;
        }

        // Render extension body first so the core panel layout can wrap it.
        extract($viewData, EXTR_SKIP);
        ob_start();
        require $resolvedViewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
            'site' => $panelSiteData(),
            'csrfField' => $rvn['csrf']->field(),
            'section' => 'database',
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    /**
     * Streams one static file with a deterministic content type.
     */
    $streamAssetFile = static function (string $assetPath): void {
        $extension = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        // Ensure no prior buffered output corrupts binary-safe asset responses.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Avoid forcing Content-Length in case upstream compression/framing differs.
        header_remove('Content-Length');
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');

        $stream = @fopen($assetPath, 'rb');
        if (!is_resource($stream)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Failed to open Database Manager asset file.';
            return;
        }

        if (@fpassthru($stream) === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Failed to stream Database Manager asset file.';
        }
        fclose($stream);
    };

    /**
     * Validates one filename token used in static asset routes.
     */
    $isSafeAssetName = static function (string $value): bool {
        return preg_match('/^[a-z0-9._-]+$/i', $value) === 1;
    };

    /**
     * Enforces Database Manager access gate shared by runtime and asset routes.
     */
    $requireDatabaseManagerAccess = static function () use ($rvn, $requirePanelLogin, $renderPublicNotFound): bool {
        $requirePanelLogin();

        if (!$rvn['auth']->canManageConfiguration()) {
            $renderPublicNotFound();
            return false;
        }

        return true;
    };

    /**
     * Runs Adminer via extension private entrypoint under panel auth + permission gates.
     */
    $serveAdminerRuntime = static function () use ($rvn, $extensionEntrypoint, $requireDatabaseManagerAccess): void {
        if (!$requireDatabaseManagerAccess()) {
            return;
        }

        if (!is_file($extensionEntrypoint)) {
            http_response_code(500);
            echo 'Database extension entrypoint is missing.';
            return;
        }

        // Extension entrypoint expects `$ravenApp` container in local scope.
        $ravenApp = $rvn;
        require $extensionEntrypoint;
    };

    /**
     * Serves Adminer static bundle files copied under `panel/ext/database/adminer/static/`.
     */
    $serveAdminerStatic = static function (array $params) use ($extensionPublicRoot, $isSafeAssetName, $streamAssetFile, $requireDatabaseManagerAccess): void {
        if (!$requireDatabaseManagerAccess()) {
            return;
        }

        $file = (string) ($params['file'] ?? '');
        if (!$isSafeAssetName($file)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $assetPath = $extensionPublicRoot . '/adminer/static/' . $file;
        if (!is_file($assetPath)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $streamAssetFile($assetPath);
    };

    /**
     * Serves Jush CSS/JS assets copied under `panel/ext/database/externals/jush/`.
     */
    $serveJushAsset = static function (array $params) use ($extensionPublicRoot, $isSafeAssetName, $streamAssetFile, $requireDatabaseManagerAccess): void {
        if (!$requireDatabaseManagerAccess()) {
            return;
        }

        $file = (string) ($params['file'] ?? '');
        if (!$isSafeAssetName($file)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $assetPath = $extensionPublicRoot . '/externals/jush/' . $file;
        if (!is_file($assetPath)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $streamAssetFile($assetPath);
    };

    /**
     * Serves Jush module files copied under `panel/ext/database/externals/jush/modules/`.
     */
    $serveJushModule = static function (array $params) use ($extensionPublicRoot, $isSafeAssetName, $streamAssetFile, $requireDatabaseManagerAccess): void {
        if (!$requireDatabaseManagerAccess()) {
            return;
        }

        $file = (string) ($params['file'] ?? '');
        if (!$isSafeAssetName($file)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $assetPath = $extensionPublicRoot . '/externals/jush/modules/' . $file;
        if (!is_file($assetPath)) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $streamAssetFile($assetPath);
    };

    /**
     * Serves a query-addressed asset for robust Adminer URL rewriting.
     *
     * Supported values for `?f=` are:
     * - `adminer/static/{file}`
     * - `externals/jush/{file}`
     * - `externals/jush/modules/{file}`
     */
    $serveAdminerAssetByQuery = static function () use ($extensionPublicRoot, $streamAssetFile, $requireDatabaseManagerAccess): void {
        if (!$requireDatabaseManagerAccess()) {
            return;
        }

        $requested = trim((string) ($_GET['f'] ?? ''));
        if ($requested === '') {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $requested = str_replace('\\', '/', $requested);
        if (str_contains($requested, '..')) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $extensionAssetPath = null;

        if (preg_match('#^adminer/static/([a-z0-9._-]+)$#i', $requested, $match) === 1) {
            $extensionAssetPath = $extensionPublicRoot . '/adminer/static/' . (string) $match[1];
        } elseif (preg_match('#^externals/jush/modules/([a-z0-9._-]+)$#i', $requested, $match) === 1) {
            $extensionAssetPath = $extensionPublicRoot . '/externals/jush/modules/' . (string) $match[1];
        } elseif (preg_match('#^externals/jush/([a-z0-9._-]+)$#i', $requested, $match) === 1) {
            $extensionAssetPath = $extensionPublicRoot . '/externals/jush/' . (string) $match[1];
        } else {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        if ($extensionAssetPath !== null && is_file($extensionAssetPath)) {
            $streamAssetFile($extensionAssetPath);
            return;
        }

        http_response_code(404);
        echo 'Not Found';
    };

    $router->add('GET', '/database', static function () use (
        $rvn,
        $panelUrl,
        $requirePanelLogin,
        $resolveAdminerEntrypoint,
        $extensionEntrypoint,
        $renderExtensionView,
        $renderPublicNotFound,
        $extensionMeta,
        $buildAdminerLaunchTargets
    ): void {
        $requirePanelLogin();

        $canManageConfiguration = $rvn['auth']->canManageConfiguration();
        if (!$canManageConfiguration) {
            $renderPublicNotFound();
            return;
        }

        $databaseConfig = (array) $rvn['config']->get('database', []);
        $driver = strtolower((string) ($databaseConfig['driver'] ?? 'sqlite'));
        $isSqlite = $driver === 'sqlite';

        $summary = [
            'driver' => $driver,
            'prefix' => (string) ($databaseConfig['prefix'] ?? ''),
            'sqlite_path' => '',
            'sqlite_file' => '',
            'mysql' => [],
            'pgsql' => [],
        ];

        if ($isSqlite) {
            $sqlite = (array) ($databaseConfig['sqlite'] ?? []);
            $summary['sqlite_path'] = (string) ($sqlite['path'] ?? '');
            $summary['sqlite_file'] = 'db.sqlite';
        } else {
            $mysql = (array) ($databaseConfig['mysql'] ?? []);
            $pgsql = (array) ($databaseConfig['pgsql'] ?? []);

            $summary['mysql'] = [
                'host' => (string) ($mysql['host'] ?? ''),
                'port' => (string) ($mysql['port'] ?? ''),
                'name' => (string) ($mysql['name'] ?? ''),
                'user' => (string) ($mysql['user'] ?? ''),
            ];
            $summary['pgsql'] = [
                'host' => (string) ($pgsql['host'] ?? ''),
                'port' => (string) ($pgsql['port'] ?? ''),
                'name' => (string) ($pgsql['name'] ?? ''),
                'user' => (string) ($pgsql['user'] ?? ''),
            ];
        }

        $targetData = $buildAdminerLaunchTargets($databaseConfig);

        $renderExtensionView([
            'canManageConfiguration' => $canManageConfiguration,
            'adminerInstalled' => $resolveAdminerEntrypoint() !== null,
            'extensionEntrypointExists' => is_file($extensionEntrypoint),
            'adminerPath' => $panelUrl('/database/adminer'),
            'extensionsPath' => $panelUrl('/extensions'),
            'databaseSummary' => $summary,
            'targets' => is_array($targetData['targets'] ?? null) ? (array) $targetData['targets'] : [],
            'selectorError' => is_string($targetData['selector_error'] ?? null) ? (string) $targetData['selector_error'] : null,
            'extensionMeta' => $extensionMeta,
        ]);
    });

    // Primary Adminer runtime route (GET + POST) inside extension-owned panel routes.
    $router->add('GET', '/database/adminer', $serveAdminerRuntime);
    $router->add('POST', '/database/adminer', $serveAdminerRuntime);
    $router->add('GET', '/database/adminer/asset', $serveAdminerAssetByQuery);

    // Asset paths emitted by Adminer when opened at `/database/adminer`.
    $router->add('GET', '/adminer/static/{file}', $serveAdminerStatic);
    $router->add('GET', '/database/static/{file}', $serveAdminerStatic);
    $router->add('GET', '/database/adminer/static/{file}', $serveAdminerStatic);
    $router->add('GET', '/externals/jush/{file}', $serveJushAsset);
    $router->add('GET', '/externals/jush/modules/{file}', $serveJushModule);
    $router->add('GET', '/database/externals/jush/{file}', $serveJushAsset);
    $router->add('GET', '/database/externals/jush/modules/{file}', $serveJushModule);

};
