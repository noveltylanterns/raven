<?php

/**
 * RAVEN CMS
 * ~/panel/index.php
 * Admin panel front controller and route dispatcher.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Auth\PanelAccess;
use Raven\Lib\Auth\PanelSessionGuard;
use Raven\Core\Diagnostics\DebugToolbarRenderer;
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarConfigResolver;
use Raven\Lib\Diagnostics\RequestProfiler;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Routing\RouteRequest;
use Raven\Lib\Routing\Router;

use function Raven\Core\Support\request_path;
use function Raven\Core\Support\redirect;

/**
 * Panel front controller for https://{domain}/{panel_path}/
 */
$rvn = require dirname(__DIR__) . '/private/raven.php';
$panelBootstrap = require __DIR__ . '/bootstrap.php';
$rvn = $panelBootstrap($rvn);

/** @var callable(): object $authController */
$authController = is_callable($rvn['auth_controller'] ?? null)
    ? $rvn['auth_controller']
    : static function (): object {
        throw new RuntimeException('Panel auth controller factory is unavailable.');
    };

/** @var callable(): object $panelController */
$panelController = is_callable($rvn['panel_controller'] ?? null)
    ? $rvn['panel_controller']
    : static function (): object {
        throw new RuntimeException('Panel controller factory is unavailable.');
    };

/** @var callable(): object $panelDashboardController */
$panelDashboardController = is_callable($rvn['panel_dashboard_controller'] ?? null)
    ? $rvn['panel_dashboard_controller']
    : static function (): object {
        throw new RuntimeException('Panel dashboard controller factory is unavailable.');
    };

/** @var callable(): object $panelTaxonomyController */
$panelTaxonomyController = is_callable($rvn['panel_taxonomy_controller'] ?? null)
    ? $rvn['panel_taxonomy_controller']
    : static function (): object {
        throw new RuntimeException('Panel taxonomy controller factory is unavailable.');
    };

/** @var callable(): object $panelRedirectController */
$panelRedirectController = is_callable($rvn['panel_redirect_controller'] ?? null)
    ? $rvn['panel_redirect_controller']
    : static function (): object {
        throw new RuntimeException('Panel redirect controller factory is unavailable.');
    };

/** @var callable(): object $panelUserController */
$panelUserController = is_callable($rvn['panel_user_controller'] ?? null)
    ? $rvn['panel_user_controller']
    : static function (): object {
        throw new RuntimeException('Panel user controller factory is unavailable.');
    };

/** @var callable(): object $panelGroupController */
$panelGroupController = is_callable($rvn['panel_group_controller'] ?? null)
    ? $rvn['panel_group_controller']
    : static function (): object {
        throw new RuntimeException('Panel group controller factory is unavailable.');
    };

/** @var callable(): object $panelContentController */
$panelContentController = is_callable($rvn['panel_content_controller'] ?? null)
    ? $rvn['panel_content_controller']
    : static function (): object {
        throw new RuntimeException('Panel content controller factory is unavailable.');
    };

/** @var callable(): object $panelPreferencesController */
$panelPreferencesController = is_callable($rvn['panel_preferences_controller'] ?? null)
    ? $rvn['panel_preferences_controller']
    : static function (): object {
        throw new RuntimeException('Panel preferences controller factory is unavailable.');
    };

/** @var callable(): array<string, mixed> $initializePanelRuntime */
$initializePanelRuntime = is_callable($rvn['initialize_panel_runtime'] ?? null)
    ? $rvn['initialize_panel_runtime']
    : static function (): array {
        throw new RuntimeException('Panel runtime initializer is unavailable.');
    };

/**
 * Normalizes request path into panel-internal path.
 */
$requestedPath = request_path();
$configuredPanelPrefix = PanelUrl::fromConfig($rvn['config']);

$internalPath = $requestedPath;

if ($requestedPath === $configuredPanelPrefix) {
    $internalPath = '/';
} elseif (str_starts_with($requestedPath, $configuredPanelPrefix . '/')) {
    $internalPath = substr($requestedPath, strlen($configuredPanelPrefix));
}

$isPanelAuthHelperInternalPath = static function (string $path) use ($internalPath): bool {
    $normalized = trim($path !== '' ? $path : $internalPath);
    $normalized = '/' . ltrim($normalized, '/');
    if ($normalized !== '/') {
        $normalized = rtrim($normalized, '/');
    }

    return in_array($normalized, [
        '/login',
        '/login/2fa',
        '/login/2fa/select',
        '/login/2fa/webauthn/options',
        '/login/2fa/webauthn/verify',
    ], true);
};

$categoryEnabled = ConfigValueParser::bool($rvn['config']->get('category.enabled', true), true);
$tagEnabled = ConfigValueParser::bool($rvn['config']->get('tag.enabled', true), true);
$_SESSION['_raven_category_enabled'] = $categoryEnabled;
$_SESSION['_raven_tag_enabled'] = $tagEnabled;

/**
 * Streams one static file from `~/panel/theme/` when panel rewrites route all
 * requests through this front controller.
 *
 * Returns true when the request was handled and response already sent.
 */
$servePanelThemeAsset = static function (string $path, string $method) use ($rvn): bool {
    // Only serve static assets for read-only methods.
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return false;
    }

    // Theme assets are publicly accessible under `/{panel_path}/theme/...`.
    if (!str_starts_with($path, '/theme/')) {
        return false;
    }

    $relativePath = ltrim(substr($path, strlen('/theme/')), '/');
    if ($relativePath === '') {
        http_response_code(404);
        echo 'Not Found';
        return true;
    }

    // Reject traversal and malformed paths before touching filesystem.
    if (
        str_contains($relativePath, '..')
        || str_contains($relativePath, "\0")
        || str_contains($relativePath, '\\')
        || preg_match('/^[a-z0-9_\/\.-]+$/i', $relativePath) !== 1
    ) {
        http_response_code(404);
        echo 'Not Found';
        return true;
    }

    $themeRoot = rtrim((string) $rvn['root'], '/') . '/panel/theme';
    $themeRootReal = realpath($themeRoot);
    $assetReal = realpath($themeRoot . '/' . $relativePath);

    // Realpath checks guarantee requested file stays under theme root.
    if (
        $themeRootReal === false
        || $assetReal === false
        || !is_file($assetReal)
        || !is_readable($assetReal)
        || ($assetReal !== $themeRootReal && !str_starts_with($assetReal, $themeRootReal . DIRECTORY_SEPARATOR))
    ) {
        http_response_code(404);
        echo 'Not Found';
        return true;
    }

    $extension = strtolower((string) pathinfo($assetReal, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };

    $isFontAsset = in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true);
    $lastModifiedTs = (int) @filemtime($assetReal);
    if ($lastModifiedTs <= 0) {
        $lastModifiedTs = time();
    }
    $fileSize = (int) @filesize($assetReal);
    if ($fileSize < 0) {
        $fileSize = 0;
    }
    $etag = '"' . sha1($assetReal . '|' . $fileSize . '|' . $lastModifiedTs) . '"';

    // Prevent partially buffered output from corrupting static asset responses.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Avoid forcing Content-Length since upstream compression may alter body size.
    header_remove('Content-Length');
    // Session cache limiter may emit anti-cache headers; clear them for static files.
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT');
    header('ETag: ' . $etag);
    // Fonts are fingerprinted by filename and safe to cache for longer.
    if ($isFontAsset) {
        header('Cache-Control: public, max-age=31536000, immutable');
    } else {
        header('Cache-Control: public, max-age=3600');
    }

    $ifNoneMatchRaw = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatchRaw !== '') {
        $etagMatches = false;
        foreach (explode(',', $ifNoneMatchRaw) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === ('W/' . $etag)) {
                $etagMatches = true;
                break;
            }
        }

        if ($etagMatches) {
            http_response_code(304);
            return true;
        }
    }

    $ifModifiedSinceRaw = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($ifModifiedSinceRaw !== '') {
        $ifModifiedSinceTs = strtotime($ifModifiedSinceRaw);
        if ($ifModifiedSinceTs !== false && $ifModifiedSinceTs >= $lastModifiedTs) {
            http_response_code(304);
            return true;
        }
    }

    if ($method === 'HEAD') {
        return true;
    }

    $stream = @fopen($assetReal, 'rb');
    if (!is_resource($stream)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Failed to open panel theme asset file.';
        return true;
    }

    if (@fpassthru($stream) === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Failed to stream panel theme asset file.';
    }
    fclose($stream);
    return true;
};

// Serve theme assets before panel route dispatch when front-controller rewrite is enabled.
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($servePanelThemeAsset($internalPath, $requestMethod)) {
    return;
}

/**
 * Builds panel URL with configured prefix.
 */
$panelUrl = static function (string $suffix = '') use ($rvn): string {
    return PanelUrl::fromConfig($rvn['config'], $suffix);
};
$shouldInitializeFullPanelRuntime = !$isPanelAuthHelperInternalPath($internalPath);
$enabledExtensions = [];
$enabledExtensionManifests = [];
if ($shouldInitializeFullPanelRuntime) {
    $rvn = $initializePanelRuntime();
    $categoryEnabled = !empty($rvn['category_enabled']);
    $tagEnabled = !empty($rvn['tag_enabled']);
    $enabledExtensions = is_array($rvn['enabled_extensions'] ?? null) ? (array) $rvn['enabled_extensions'] : [];
    $enabledExtensionManifests = is_array($rvn['enabled_extension_manifests'] ?? null)
        ? (array) $rvn['enabled_extension_manifests']
        : [];
}

/**
 * Synchronizes lightweight identity data for the personalized welcome heading.
 * Delegates to PanelSessionGuard so this path and the core panel route path
 * always write the same session shape from the same preferences keys.
 */
$syncPanelIdentity = static function () use ($rvn): void {
    (new PanelSessionGuard())->syncPanelIdentityInSession($rvn['auth']);
};

/**
 * Returns true when current request targets direct panel auth entry paths.
 */
$isGuestPanelLoginEntryInternalPath = static function () use ($internalPath): bool {
    $path = '/' . ltrim($internalPath, '/');
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return in_array($path, ['/', '/login', '/login/2fa'], true);
};

/**
 * Enforces login + panel-access permission for extension routes.
 */
$requirePanelLoginForExtension = static function () use (
    $rvn,
    $panelUrl,
    $syncPanelIdentity,
    $isGuestPanelLoginEntryInternalPath,
    $panelController
): void {
    (new PanelSessionGuard())->requirePanelLogin(
        $rvn['auth'],
        $isGuestPanelLoginEntryInternalPath(),
        $panelUrl('/login'),
        $panelUrl('/login/2fa'),
        static function () use ($panelController): void {
            $panelController()->renderPublicNotFound();
        }
    );
    $syncPanelIdentity();
};

/**
 * Returns panel theme value for current user.
 */
$normalizePanelTheme = static function (string $theme, bool $allowDefault): ?string {
    $normalized = strtolower(trim($theme));
    if ($normalized === '') {
        return $allowDefault ? 'default' : 'corp';
    }

    if ($allowDefault && $normalized === 'default') {
        return 'default';
    }

    if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
        return $normalized;
    }

    if (in_array($normalized, ['light', 'raven', 'default'], true)) {
        return 'corp';
    }

    if ($normalized === 'dark') {
        return 'midnight';
    }

    return null;
};

/**
 * Returns panel theme value for current user.
 */
$defaultPanelTheme = static function () use ($rvn): string {
    $theme = strtolower(trim((string) $rvn['config']->get('panel.theme', 'corp')));
    if (in_array($theme, ['light', 'raven', 'default', 'corp'], true)) {
        return 'corp';
    }
    if (in_array($theme, ['dark', 'midnight'], true)) {
        return 'midnight';
    }
    if ($theme === 'ice') {
        return 'ice';
    }

    return 'corp';
};

/**
 * Returns panel theme value for current user.
 */
$currentUserTheme = static function () use ($rvn, $defaultPanelTheme, $normalizePanelTheme): string {
    $theme = $defaultPanelTheme();
    $userId = $rvn['auth']->userId();
    if ($userId !== null) {
        $prefs = $rvn['auth']->userPreferences($userId);
        $candidate = $normalizePanelTheme((string) ($prefs['theme'] ?? 'default'), true);
        if (is_string($candidate)) {
            $theme = $candidate === 'default' ? $defaultPanelTheme() : $candidate;
        }
    }

    return $theme;
};

/**
 * Returns true when current user has one panel-side permission bit.
 */
$hasPanelPermissionBit = static function (int $bit) use ($rvn): bool {
    return $rvn['auth']->hasPanelPermissionBit($bit);
};

$_SESSION['_raven_nav_stock'] = [
    'content' => [
        'create_page' => $hasPanelPermissionBit(PanelAccess::PAGES_CREATE),
        'list_pages' => $hasPanelPermissionBit(PanelAccess::PAGES_VIEW),
    ],
    'accounts' => [
        'groups' => $hasPanelPermissionBit(PanelAccess::GROUPS_VIEW),
        'users' => $hasPanelPermissionBit(PanelAccess::USERS_VIEW),
    ],
    'taxonomy' => [
        'categories' => $categoryEnabled && $hasPanelPermissionBit(PanelAccess::CATEGORIES_VIEW),
        'channels' => $hasPanelPermissionBit(PanelAccess::CHANNELS_VIEW),
        'redirects' => $hasPanelPermissionBit(PanelAccess::REDIRECTS_VIEW),
        'routing' => $hasPanelPermissionBit(PanelAccess::ROUTING_VIEW),
        'tags' => $tagEnabled && $hasPanelPermissionBit(PanelAccess::TAGS_VIEW),
    ],
    'system' => [
        'configuration' => $hasPanelPermissionBit(PanelAccess::CONFIGURATION_VIEW),
        'logs' => $hasPanelPermissionBit(PanelAccess::CONFIGURATION_VIEW),
        'themes' => $hasPanelPermissionBit(PanelAccess::THEMES_VIEW),
        'extensions' => $hasPanelPermissionBit(PanelAccess::EXTENSIONS_VIEW),
        'update' => $hasPanelPermissionBit(PanelAccess::MANAGE_CONFIGURATION),
        'system_extensions' => $hasPanelPermissionBit(PanelAccess::CONFIGURATION_VIEW),
    ],
];

$extensionPermissionCatalog = [];
if ($shouldInitializeFullPanelRuntime) {
    // Resolve extension permission levels/bit assignments from controller-managed state.
    $extensionPermissionCatalog = $panelController()->extensionPanelPermissionMapForDirectories(array_keys($enabledExtensionManifests));

    $_SESSION['_raven_extension_permission_masks'] = $extensionPermissionCatalog;
    $_SESSION['_raven_enabled_extensions'] = array_keys($enabledExtensions);

    // Build dedicated nav links by extension type.
    $extensionNavItems = [];
    $moduleNavItems = [];
    $systemExtensionNavItems = [];
    $canViewSystemExtensions = !empty(($_SESSION['_raven_nav_stock']['system']['system_extensions'] ?? false));
    foreach ($enabledExtensionManifests as $directoryName => $manifest) {
        $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }

        $panelRoutesFile = $rvn['root'] . '/private/ext/' . $directoryName . '/lib/routes_panel.php';
        if (!is_file($panelRoutesFile)) {
            continue;
        }

        $isSystemType = $type === 'system'
            || !empty($manifest['system_extension']);
        $permissionMeta = $extensionPermissionCatalog[$directoryName] ?? null;
        $requiredPermissionBit = 0;
        if (is_array($permissionMeta)) {
            $defaultLevel = strtolower(trim((string) ($permissionMeta['default_level'] ?? '')));
            $levelRows = is_array($permissionMeta['levels'] ?? null) ? $permissionMeta['levels'] : [];
            foreach ($levelRows as $levelRow) {
                if (!is_array($levelRow)) {
                    continue;
                }

                $levelKey = strtolower(trim((string) ($levelRow['key'] ?? '')));
                if ($defaultLevel !== '' && $levelKey !== $defaultLevel) {
                    continue;
                }

                $requiredPermissionBit = (int) ($levelRow['bit'] ?? 0);
                break;
            }
        }

        $item = [
            'label' => (string) $manifest['name'],
            'path' => $panelUrl('/' . ltrim($directoryName, '/')),
            'section' => $directoryName,
        ];

        if ($isSystemType) {
            if ($canViewSystemExtensions) {
                $systemExtensionNavItems[] = $item;
            }
            continue;
        }

        if ($requiredPermissionBit <= 0 || !$hasPanelPermissionBit($requiredPermissionBit)) {
            continue;
        }

        if ($type === 'module') {
            $moduleNavItems[] = $item;
            continue;
        }

        $extensionNavItems[] = $item;
    }

    usort($extensionNavItems, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    usort($moduleNavItems, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    usort($systemExtensionNavItems, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    $_SESSION['_raven_nav_extensions'] = $extensionNavItems;
    $_SESSION['_raven_nav_modules'] = $moduleNavItems;
    $_SESSION['_raven_nav_system_extensions'] = $systemExtensionNavItems;

    // Provide channel-aware shortcuts for Create Page sidebar/mobile accordion sublinks.
    $pageCreateChannelItems = [];
    if ($hasPanelPermissionBit(PanelAccess::PAGES_CREATE)) {
        foreach ($rvn['channel']->listOptions() as $channelOption) {
            if (!is_array($channelOption)) {
                continue;
            }

            $channelName = trim((string) ($channelOption['name'] ?? ''));
            $channelSlug = strtolower(trim((string) ($channelOption['slug'] ?? '')));
            if ($channelName === '' || $channelSlug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $channelSlug) !== 1) {
                continue;
            }

            $pageCreateChannelItems[] = [
                'label' => $channelName,
                'slug' => $channelSlug,
            ];
        }
    }
    $_SESSION['_raven_nav_page_create_channels'] = $pageCreateChannelItems;
} else {
    $_SESSION['_raven_extension_permission_masks'] = [];
    $_SESSION['_raven_enabled_extensions'] = [];
    $_SESSION['_raven_nav_extensions'] = [];
    $_SESSION['_raven_nav_modules'] = [];
    $_SESSION['_raven_nav_system_extensions'] = [];
    $_SESSION['_raven_nav_page_create_channels'] = [];
}

$router = new Router();

// Authentication routes.
// These remain intentionally small wrappers so auth logic stays centralized
// inside AuthController and can be reused by any future panel entrypoints.
$router->add('GET', '/login', static function () use ($authController): void {
    $authController()->showLogin();
});

$router->add('POST', '/login', static function () use ($authController): void {
    $authController()->login($_POST);
});

$router->add('GET', '/login/2fa', static function () use ($authController): void {
    $authController()->showLoginTwoFactor();
});

$router->add('POST', '/login/2fa', static function () use ($authController): void {
    $authController()->loginTwoFactor($_POST);
});

$router->add('POST', '/login/2fa/select', static function () use ($authController): void {
    $authController()->loginTwoFactorSelect($_POST);
});

$router->add('POST', '/login/2fa/webauthn/options', static function () use ($authController): void {
    $authController()->loginTwoFactorWebauthnOptions($_POST);
});

$router->add('POST', '/login/2fa/webauthn/verify', static function () use ($authController): void {
    $authController()->loginTwoFactorWebauthnVerify($_POST);
});

$router->add('POST', '/logout', static function () use ($authController): void {
    $authController()->logout($_POST);
});

// Dashboard route.
// Serves the panel landing page after access checks.
$router->add('GET', '/', static function () use ($panelDashboardController): void {
    $panelDashboardController()->dashboard();
});

// Page routes.
// Includes list/create/edit/save plus gallery media and delete actions.
$router->add('GET', '/page', static function () use ($panelContentController): void {
    $panelContentController()->pageList();
});

$router->add('GET', '/page/edit', static function () use ($panelContentController): void {
    $panelContentController()->pageEdit(null);
});

$router->add('GET', '/page/edit/{id}', static function (array $params) use ($panelContentController, $rvn): void {
    $id = $rvn['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelContentController()->pageEdit($id);
});

$router->add('POST', '/page/save', static function () use ($panelContentController): void {
    $panelContentController()->pageSave($_POST);
});

// Uploads one image into a page gallery (Media tab action).
$router->add('POST', '/page/gallery/upload', static function () use ($panelContentController): void {
    $panelContentController()->pageGalleryUpload($_POST, $_FILES);
});

// Deletes one gallery image from a page (Media tab action).
$router->add('POST', '/page/gallery/delete', static function () use ($panelContentController): void {
    $panelContentController()->pageGalleryDelete($_POST);
});

// Deletes one page from the Pages index action column.
$router->add('POST', '/page/delete', static function () use ($panelContentController): void {
    $panelContentController()->pageDelete($_POST);
});

// Channel routes (list + edit + save + delete).
$router->add('GET', '/channel', static function () use ($panelTaxonomyController): void {
    $panelTaxonomyController()->channelList();
});

$router->add('GET', '/channel/edit', static function () use ($panelTaxonomyController): void {
    $panelTaxonomyController()->channelEdit(null);
});

$router->add('GET', '/channel/edit/{id}', static function (array $params) use ($panelTaxonomyController, $rvn): void {
    $id = $rvn['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelTaxonomyController()->channelEdit($id);
});

$router->add('POST', '/channel/save', static function () use ($panelTaxonomyController): void {
    $panelTaxonomyController()->channelSave($_POST, $_FILES);
});

$router->add('POST', '/channel/delete', static function () use ($panelTaxonomyController): void {
    $panelTaxonomyController()->channelDelete($_POST);
});

// Category/Tag/Redirect/User/Group routes.
// Kept explicit (instead of dynamic routing) for clarity and predictable auth gates.

if ($categoryEnabled) {
    $router->add('GET', '/category', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categoryList();
    });

    $router->add('GET', '/category/edit', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categoryEdit(null);
    });

    $router->add('GET', '/category/edit/{id}', static function (array $params) use ($panelTaxonomyController, $rvn): void {
        $id = $rvn['input']->int($params['id'] ?? null, 1);

        if ($id === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $panelTaxonomyController()->categoryEdit($id);
    });

    $router->add('POST', '/category/save', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categorySave($_POST, $_FILES);
    });

    $router->add('POST', '/category/delete', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categoryDelete($_POST);
    });

    $router->add('GET', '/category/set', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categorySetList();
    });

    $router->add('GET', '/category/set/edit', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categorySetEdit(null);
    });

    $router->add('GET', '/category/set/edit/{id}', static function (array $params) use ($panelTaxonomyController, $rvn): void {
        $id = $rvn['input']->int($params['id'] ?? null, 0);

        if ($id === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $panelTaxonomyController()->categorySetEdit($id);
    });

    $router->add('POST', '/category/set/save', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categorySetSave($_POST);
    });

    $router->add('POST', '/category/set/delete', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->categorySetDelete($_POST);
    });
}

if ($tagEnabled) {
    $router->add('GET', '/tag', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagList();
    });

    $router->add('GET', '/tag/edit', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagEdit(null);
    });

    $router->add('GET', '/tag/edit/{id}', static function (array $params) use ($panelTaxonomyController, $rvn): void {
        $id = $rvn['input']->int($params['id'] ?? null, 1);

        if ($id === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $panelTaxonomyController()->tagEdit($id);
    });

    $router->add('POST', '/tag/save', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagSave($_POST, $_FILES);
    });

    $router->add('POST', '/tag/delete', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagDelete($_POST);
    });

    $router->add('GET', '/tag/set', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagSetList();
    });

    $router->add('GET', '/tag/set/edit', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagSetEdit(null);
    });

    $router->add('GET', '/tag/set/edit/{id}', static function (array $params) use ($panelTaxonomyController, $rvn): void {
        $id = $rvn['input']->int($params['id'] ?? null, 0);

        if ($id === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $panelTaxonomyController()->tagSetEdit($id);
    });

    $router->add('POST', '/tag/set/save', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagSetSave($_POST);
    });

    $router->add('POST', '/tag/set/delete', static function () use ($panelTaxonomyController): void {
        $panelTaxonomyController()->tagSetDelete($_POST);
    });
}

$router->add('GET', '/redirect', static function () use ($panelRedirectController): void {
    $panelRedirectController()->redirectList();
});

$router->add('GET', '/redirect/edit', static function () use ($panelRedirectController): void {
    $panelRedirectController()->redirectEdit(null);
});

$router->add('GET', '/redirect/edit/{id}', static function (array $params) use ($panelRedirectController, $rvn): void {
    $id = $rvn['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelRedirectController()->redirectEdit($id);
});

$router->add('POST', '/redirect/save', static function () use ($panelRedirectController): void {
    $panelRedirectController()->redirectSave($_POST);
});

$router->add('POST', '/redirect/delete', static function () use ($panelRedirectController): void {
    $panelRedirectController()->redirectDelete($_POST);
});

$router->add('GET', '/user', static function () use ($panelUserController): void {
    $panelUserController()->userList();
});

$router->add('GET', '/user/edit', static function () use ($panelUserController): void {
    $panelUserController()->userEdit(null);
});

$router->add('GET', '/user/edit/{id}', static function (array $params) use ($panelUserController, $rvn): void {
    $id = $rvn['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelUserController()->userEdit($id);
});

$router->add('POST', '/user/save', static function () use ($panelUserController): void {
    $panelUserController()->userSave($_POST, $_FILES);
});

$router->add('POST', '/user/delete', static function () use ($panelUserController): void {
    $panelUserController()->userDelete($_POST);
});

$router->add('GET', '/user/invites', static function () use ($panelUserController): void {
    $panelUserController()->userInvites();
});

$router->add('POST', '/user/invites/create', static function () use ($panelUserController): void {
    $panelUserController()->userInvitesCreate($_POST);
});

$router->add('POST', '/user/invites/generate', static function () use ($panelUserController): void {
    $panelUserController()->userInvitesGenerate($_POST);
});

$router->add('POST', '/user/invites/delete', static function () use ($panelUserController): void {
    $panelUserController()->userInvitesDelete($_POST);
});

$router->add('GET', '/group', static function () use ($panelGroupController): void {
    $panelGroupController()->groupList();
});

$router->add('GET', '/group/edit', static function () use ($panelGroupController): void {
    $panelGroupController()->groupEdit(null);
});

$router->add('GET', '/group/edit/{id}', static function (array $params) use ($panelGroupController, $rvn): void {
    $id = $rvn['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelGroupController()->groupEdit($id);
});

$router->add('POST', '/group/save', static function () use ($panelGroupController): void {
    $panelGroupController()->groupSave($_POST, $_FILES);
});

$router->add('POST', '/group/delete', static function () use ($panelGroupController): void {
    $panelGroupController()->groupDelete($_POST);
});

$router->add('GET', '/preferences', static function () use ($panelPreferencesController): void {
    $panelPreferencesController()->preferences();
});

$router->add('POST', '/preferences/save', static function () use ($panelPreferencesController): void {
    $panelPreferencesController()->preferencesSave($_POST, $_FILES);
});

$router->add('POST', '/preferences/2fa/totp/setup', static function () use ($panelPreferencesController): void {
    $panelPreferencesController()->preferencesTotpSetup($_POST);
});

$router->add('POST', '/preferences/2fa/recovery/generate', static function () use ($panelPreferencesController): void {
    $panelPreferencesController()->preferencesRecoveryCodeGenerate($_POST);
});

$router->add('POST', '/preferences/2fa/webauthn/options', static function () use ($panelPreferencesController): void {
    $panelPreferencesController()->preferencesWebauthnCreateOptions($_POST);
});

$router->add('POST', '/preferences/2fa/webauthn/register', static function () use ($panelPreferencesController): void {
    $panelPreferencesController()->preferencesWebauthnRegister($_POST);
});

// Configuration routes.
// Configuration editing is restricted to Super Admin capability.
$router->add('GET', '/configuration', static function () use ($panelController): void {
    $panelController()->configuration();
});

$router->add('GET', '/update', static function () use ($panelController): void {
    $panelController()->update();
});

$router->add('POST', '/update/action', static function () use ($panelController): void {
    $panelController()->updateAction($_POST);
});

$router->add('POST', '/configuration/save', static function () use ($panelController): void {
    $panelController()->configurationSave($_POST);
});

// Routing inventory route.
$router->add('GET', '/routing', static function () use ($panelController): void {
    $panelController()->routing();
});

$router->add('GET', '/routing/export', static function () use ($panelController): void {
    $panelController()->routingExport();
});

// Event log routes.
$router->add('GET', '/logs', static function () use ($panelController): void {
    $panelController()->logs();
});

$router->add('GET', '/logs/export', static function () use ($panelController): void {
    $panelController()->logsExport();
});

$router->add('POST', '/logs/clear', static function () use ($panelController): void {
    $panelController()->logsClear();
});

// Public Theme manager routes.
$router->add('GET', '/themes', static function () use ($panelController): void {
    $panelController()->themes();
});

$router->add('POST', '/themes/enable', static function () use ($panelController): void {
    $panelController()->themesEnable($_POST);
});

$router->add('POST', '/themes/create', static function () use ($panelController): void {
    $panelController()->themesCreate($_POST);
});

$router->add('POST', '/themes/upload', static function () use ($panelController): void {
    $panelController()->themesUpload($_POST, $_FILES);
});

$router->add('GET', '/themes/export', static function () use ($panelController): void {
    $panelController()->themesExport($_GET);
});

$router->add('POST', '/themes/uninstall', static function () use ($panelController): void {
    $panelController()->themesUninstall($_POST);
});

// Extensions management routes (placeholder system foundation for future plugin runtime wiring).
$router->add('GET', '/extensions', static function () use ($panelController): void {
    $panelController()->extensions();
});

$router->add('POST', '/extensions/toggle', static function () use ($panelController): void {
    $panelController()->extensionsToggle($_POST);
});

$router->add('POST', '/extensions/upload', static function () use ($panelController): void {
    $panelController()->extensionsUpload($_POST, $_FILES);
});

$router->add('GET', '/extensions/export', static function () use ($panelController): void {
    $panelController()->extensionsExport($_GET);
});

$router->add('POST', '/extensions/create', static function () use ($panelController): void {
    $panelController()->extensionsCreate($_POST);
});

$router->add('POST', '/extensions/uninstall', static function () use ($panelController): void {
    $panelController()->extensionsUninstall($_POST);
});

$router->add('POST', '/extensions/permission', static function () use ($panelController): void {
    $panelController()->extensionsPermission($_POST);
});

// Extension route registration.
//
// Each enabled extension can optionally provide `private/ext/{name}/lib/routes_panel.php`
// that returns a callable with signature:
//   function (Router $router, array $context): void
//
// Context keys:
// - rvn: container array from bootstrap
// - panelUrl: callable(string): string for building panel-prefixed links
// - requirePanelLogin: callable(): void guard for panel access
// - currentUserTheme: callable(): string current panel theme slug
// - renderPublicNotFound: callable(): void themed public 404 responder
// - extensionDirectory: enabled extension folder name
// - extensionServices: callable(?string $extensionDirectory = null): array lazy resolver for one extension's service map
// - extensionRequiredPermissionBit: required default extension permission bit
// - extensionPermissionOptions: extension-level permission options map (`bit => label`)
// - extensionPermissionBits: extension-level bit map (`key => bit`)
// - extensionPermissionLevels: extension-level rows from manifest
// - extensionDefaultPermissionLevel: default extension-level key
// - requireExtensionPermission: callable(?string $levelKey = null): void
foreach (array_keys($enabledExtensions) as $extensionName) {
    $routesFile = $rvn['root'] . '/private/ext/' . $extensionName . '/lib/routes_panel.php';
    if (!is_file($routesFile)) {
        continue;
    }

    /** @var mixed $registrar */
    $registrar = require $routesFile;
    if (!is_callable($registrar)) {
        continue;
    }

    $extensionRouteRvn = $rvn;
    if (is_callable($rvn['extension_context_for'] ?? null)) {
        /** @var callable(string): array<string, mixed> $extensionContextFor */
        $extensionContextFor = $rvn['extension_context_for'];
        $extensionContext = $extensionContextFor($extensionName);
        if ($extensionContext !== []) {
            $extensionRouteRvn = array_replace($extensionRouteRvn, $extensionContext);
        }
    }

    $manifest = $enabledExtensionManifests[$extensionName] ?? null;
    if (!is_array($manifest)) {
        $manifest = [
            'type' => 'plugin',
            'system_extension' => false,
        ];
    }
    $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
    if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
        $type = 'plugin';
    }
    $isSystemType = $type === 'system'
        || !empty($manifest['system_extension']);
    $permissionMeta = $extensionPermissionCatalog[$extensionName] ?? null;
    $levelRows = is_array($permissionMeta['levels'] ?? null) ? $permissionMeta['levels'] : [];
    $defaultLevel = strtolower(trim((string) ($permissionMeta['default_level'] ?? '')));
    $extensionPermissionBits = [];
    $extensionPermissionOptions = [];
    $requiredPermissionBit = 0;
    foreach ($levelRows as $levelRow) {
        if (!is_array($levelRow)) {
            continue;
        }

        $levelKey = strtolower(trim((string) ($levelRow['key'] ?? '')));
        if ($levelKey === '') {
            continue;
        }

        $levelBit = (int) ($levelRow['bit'] ?? 0);
        if ($levelBit <= 0) {
            continue;
        }

        $levelLabel = trim((string) ($levelRow['label'] ?? ''));
        if ($levelLabel === '') {
            $levelLabel = ucfirst(str_replace(['-', '_'], ' ', $levelKey));
        }

        $extensionPermissionBits[$levelKey] = $levelBit;
        $extensionPermissionOptions[$levelBit] = $levelLabel;
        if ($requiredPermissionBit <= 0 && ($defaultLevel === '' || $levelKey === $defaultLevel)) {
            $requiredPermissionBit = $levelBit;
        }
    }
    if ($requiredPermissionBit <= 0 && $extensionPermissionBits !== []) {
        $requiredPermissionBit = (int) reset($extensionPermissionBits);
    }

    $extensionRequirePanelAccess = $requirePanelLoginForExtension;
    if ($isSystemType) {
        $extensionRequirePanelAccess = static function () use ($requirePanelLoginForExtension, $rvn, $panelController): void {
            $requirePanelLoginForExtension();
            if (!$rvn['auth']->hasPanelPermissionBit(PanelAccess::CONFIGURATION_VIEW)) {
                $panelController()->renderPublicNotFound();
                exit;
            }
        };
    } else {
        $extensionRequirePanelAccess = static function () use (
            $requirePanelLoginForExtension,
            $hasPanelPermissionBit,
            $requiredPermissionBit,
            $panelController
        ): void {
            $requirePanelLoginForExtension();
            if ($requiredPermissionBit <= 0 || !$hasPanelPermissionBit($requiredPermissionBit)) {
                $panelController()->renderPublicNotFound();
                exit;
            }
        };
    }

    $requireExtensionPermission = static function (?string $levelKey = null) use (
        $requirePanelLoginForExtension,
        $hasPanelPermissionBit,
        $extensionPermissionBits,
        $requiredPermissionBit,
        $panelController
    ): void {
        $requirePanelLoginForExtension();

        $resolvedLevel = strtolower(trim((string) ($levelKey ?? '')));
        $targetBit = 0;
        if ($resolvedLevel !== '' && isset($extensionPermissionBits[$resolvedLevel])) {
            $targetBit = (int) $extensionPermissionBits[$resolvedLevel];
        } else {
            $targetBit = (int) $requiredPermissionBit;
        }

        if ($targetBit <= 0 || !$hasPanelPermissionBit($targetBit)) {
            $panelController()->renderPublicNotFound();
            exit;
        }
    };

    $registrar($router, [
        'rvn' => $extensionRouteRvn,
        'panelUrl' => $panelUrl,
        'requirePanelLogin' => $extensionRequirePanelAccess,
        'requireExtensionPermission' => $requireExtensionPermission,
        'currentUserTheme' => $currentUserTheme,
        'renderPublicNotFound' => static function () use ($panelController): void {
            $panelController()->renderPublicNotFound();
        },
        'extensionServices' => is_callable($rvn['extension_services_for'] ?? null)
            ? $rvn['extension_services_for']
            : static fn (?string $extensionDirectory = null): array => [],
        'extensionDirectory' => $extensionName,
        'extensionRequiredPermissionBit' => $requiredPermissionBit,
        'extensionPermissionOptions' => $extensionPermissionOptions,
        'extensionPermissionBits' => $extensionPermissionBits,
        'extensionPermissionLevels' => $levelRows,
        'extensionDefaultPermissionLevel' => $defaultLevel,
        'setExtensionPermissionPath' => $panelUrl('/extensions/permission'),
    ]);
}

$method = $requestMethod;
$debugToolbarSettings = DebugToolbarConfigResolver::fromConfig($rvn['config']);
$debugToolbarEnabled = false;
$canRenderPanelDebugToolbar = static function () use ($rvn, $isPanelAuthHelperInternalPath, $internalPath): bool {
    if (!isset($rvn['auth']) || $isPanelAuthHelperInternalPath($internalPath)) {
        return false;
    }

    $userId = $rvn['auth']->userId();
    if ($userId === null || !$rvn['auth']->canManageConfiguration($userId)) {
        return false;
    }

    return $rvn['auth']->isTwoFactorVerifiedForUser($userId);
};

if (
    $method === 'GET'
    && $canRenderPanelDebugToolbar()
) {
    if ($debugToolbarSettings['show_on_panel']) {
        RequestProfiler::start((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 'panel');
        RequestProfiler::enable();
        $debugToolbarEnabled = true;
    }
}

if ($debugToolbarEnabled) {
    ob_start(static function (string $body) use ($rvn, $debugToolbarSettings, $internalPath, $method, $canRenderPanelDebugToolbar): string {
        if (!RequestProfiler::isEnabled() || !DebugToolbarRenderer::isHtmlResponseCandidate($body)) {
            return $body;
        }

        // Defense-in-depth: always re-check current auth permission before rendering.
        if (!$canRenderPanelDebugToolbar()) {
            return $body;
        }

        $toolbarHtml = DebugToolbarRenderer::render(
            [
                'show_benchmarks' => (bool) ($debugToolbarSettings['show_benchmarks'] ?? true),
                'show_queries' => (bool) ($debugToolbarSettings['show_queries'] ?? true),
                'show_stack_trace' => (bool) ($debugToolbarSettings['show_stack_trace'] ?? true),
                'show_request' => (bool) ($debugToolbarSettings['show_request'] ?? true),
                'show_environment' => (bool) ($debugToolbarSettings['show_environment'] ?? true),
            ],
            RequestProfiler::snapshot(),
            [
                'scope' => 'panel',
                'can_manage_configuration' => true,
                'status_code' => http_response_code(),
                'request_method' => $method,
                'request_path' => $internalPath,
                'hostname' => (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')),
            ]
        );

        if ($toolbarHtml === '') {
            return $body;
        }

        return DebugToolbarRenderer::inject($body, $toolbarHtml);
    });
}

// Final route dispatch for panel-internal path.
// Unknown panel routes intentionally render the public themed 404 response.
$dispatchResult = $router->dispatch(new RouteRequest($method, $internalPath));
if (!$dispatchResult->isHandled()) {
    if ($shouldInitializeFullPanelRuntime) {
        $panelController()->renderPublicNotFound();
    } else {
        http_response_code(404);
        echo 'Not Found';
    }
}

// Fallback scheduler: mirrors the public entrypoint trigger.
// Shared timestamp file means whichever entrypoint receives the next request after the
// 60 s window fires the scheduler — no double-runs between public and panel traffic.
if (in_array($rvn['config']->get('site.scheduler', 'always'), ['always', 'panel'], true)) {
    $schedulerStampFile = dirname(__DIR__) . '/private/dat/scheduler_last_run';
    $lastRun = is_file($schedulerStampFile) ? (int) @file_get_contents($schedulerStampFile) : 0;
    if (time() - $lastRun >= 60) {
        if (is_callable($rvn['boot_extensions'] ?? null)) {
            /** @var callable(): array<string, mixed> $bootExtensions */
            $bootExtensions = $rvn['boot_extensions'];
            $rvn = $bootExtensions();
        }
        @file_put_contents($schedulerStampFile, (string) time());
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $rvn['scheduler']->runDue(['root' => dirname(__DIR__), 'rvn' => $rvn]);
    }
}
