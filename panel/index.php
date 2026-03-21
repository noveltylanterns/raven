<?php

/**
 * RAVEN CMS
 * ~/panel/index.php
 * Admin panel front controller and route dispatcher.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\AuthController;
use Raven\Controller\PanelController;
use Raven\Core\Auth\PanelAccess;
use Raven\Core\Debug\DebugToolbarRenderer;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Debug\DebugToolbarConfigResolver;
use Raven\Lib\Profiling\RequestProfiler;
use Raven\Lib\Routing\PanelUrl;
use Raven\Lib\Routing\RouteRequest;
use Raven\Lib\Routing\Router;

use function Raven\Core\Support\request_path;
use function Raven\Core\Support\redirect;

/**
 * Panel front controller for https://{domain}/{panel_path}/
 */
$app = require dirname(__DIR__) . '/private/raven.php';

$authController = new AuthController(
    $app['view'],
    $app['config'],
    $app['auth'],
    $app['input'],
    $app['csrf']
);

$panelController = new PanelController(
    $app['view'],
    $app['config'],
    $app['auth'],
    $app['input'],
    $app['csrf'],
    $app['page_images'],
    $app['page_image_manager'],
    $app['categories'],
    $app['channels'],
    $app['groups'],
    $app['pages'],
    $app['redirects'],
    $app['tags'],
    $app['taxonomy'],
    $app['users'],
    $app['invite_tokens']
);

$categoryEnabled = ConfigValueParser::bool($app['config']->get('category.enabled', true), true);
$tagEnabled = ConfigValueParser::bool($app['config']->get('tag.enabled', true), true);

/**
 * Normalizes request path into panel-internal path.
 */
$requestedPath = request_path();
$configuredPanelPrefix = PanelUrl::fromConfig($app['config']);

$internalPath = $requestedPath;

if ($requestedPath === $configuredPanelPrefix) {
    $internalPath = '/';
} elseif (str_starts_with($requestedPath, $configuredPanelPrefix . '/')) {
    $internalPath = substr($requestedPath, strlen($configuredPanelPrefix));
}

/**
 * Streams one static file from `~/panel/theme/` when panel rewrites route all
 * requests through this front controller.
 *
 * Returns true when the request was handled and response already sent.
 */
$servePanelThemeAsset = static function (string $path, string $method) use ($app): bool {
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

    $themeRoot = rtrim((string) $app['root'], '/') . '/panel/theme';
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
$panelUrl = static function (string $suffix = '') use ($app): string {
    return PanelUrl::fromConfig($app['config'], $suffix);
};

$enabledState = ExtensionRegistry::enabledMap((string) $app['root']);

// Compute enabled, manifest-valid extensions once for panel nav and route registration.
$enabledExtensions = [];
$enabledExtensionManifests = [];
foreach (array_keys($enabledState) as $directoryName) {
    if (!is_dir($app['root'] . '/private/ext/' . $directoryName)) {
        continue;
    }

    $manifest = ExtensionRegistry::readManifest((string) $app['root'], $directoryName);
    if ($manifest === null) {
        continue;
    }

    $enabledExtensions[$directoryName] = true;
    $enabledExtensionManifests[$directoryName] = $manifest;
}

/**
 * Synchronizes lightweight identity data for the personalized welcome heading.
 */
$syncPanelIdentity = static function () use ($app): void {
    $userId = $app['auth']->userId();
    if ($userId === null) {
        unset($_SESSION['rvn-panel-identity']);
        unset($_SESSION['_raven_can_manage_content']);
        unset($_SESSION['_raven_can_manage_taxonomy']);
        unset($_SESSION['_raven_can_manage_users']);
        unset($_SESSION['_raven_can_manage_groups']);
        unset($_SESSION['_raven_can_manage_configuration']);
        return;
    }

    $preferences = $app['auth']->userPreferences($userId);
    if ($preferences === null) {
        unset($_SESSION['rvn-panel-identity']);
        unset($_SESSION['_raven_can_manage_content']);
        unset($_SESSION['_raven_can_manage_taxonomy']);
        unset($_SESSION['_raven_can_manage_users']);
        unset($_SESSION['_raven_can_manage_groups']);
        unset($_SESSION['_raven_can_manage_configuration']);
        return;
    }

    $_SESSION['rvn-panel-identity'] = [
        'display_name' => trim((string) ($preferences['display_name'] ?? '')),
        'username' => trim((string) ($preferences['username'] ?? '')),
    ];
    $_SESSION['_raven_can_manage_content'] = $app['auth']->canManageContent();
    $_SESSION['_raven_can_manage_taxonomy'] = $app['auth']->canManageTaxonomy();
    $_SESSION['_raven_can_manage_users'] = $app['auth']->canManageUsers();
    $_SESSION['_raven_can_manage_groups'] = $app['auth']->canManageGroups();
    $_SESSION['_raven_can_manage_configuration'] = $app['auth']->canManageConfiguration();
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
    $app,
    $panelUrl,
    $syncPanelIdentity,
    $isGuestPanelLoginEntryInternalPath,
    $panelController
): void {
    if (!$app['auth']->isLoggedIn()) {
        if ($isGuestPanelLoginEntryInternalPath()) {
            redirect($panelUrl('/login'));
        }

        $panelController->renderPublicNotFound();
        exit;
    }

    if (!$app['auth']->canAccessPanel()) {
        $app['auth']->logout();
        if ($isGuestPanelLoginEntryInternalPath()) {
            redirect($panelUrl('/login'));
        }

        $panelController->renderPublicNotFound();
        exit;
    }

    $userId = $app['auth']->userId();
    if ($userId !== null && !$app['auth']->isTwoFactorVerifiedForUser($userId)) {
        if ($app['auth']->pendingTwoFactorUserId() === $userId) {
            redirect($panelUrl('/login/2fa'));
        }

        $app['auth']->logout();
        if ($isGuestPanelLoginEntryInternalPath()) {
            redirect($panelUrl('/login'));
        }

        $panelController->renderPublicNotFound();
        exit;
    }

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
$defaultPanelTheme = static function () use ($app): string {
    $theme = strtolower(trim((string) $app['config']->get('panel.default_theme', 'corp')));
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
$currentUserTheme = static function () use ($app, $defaultPanelTheme, $normalizePanelTheme): string {
    $theme = $defaultPanelTheme();
    $userId = $app['auth']->userId();
    if ($userId !== null) {
        $prefs = $app['auth']->userPreferences($userId);
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
$hasPanelPermissionBit = static function (int $bit) use ($app): bool {
    return $app['auth']->hasPanelPermissionBit($bit);
};

// Resolve extension permission levels/bit assignments from controller-managed state.
$extensionPermissionCatalog = $panelController->extensionPanelPermissionMapForDirectories(array_keys($enabledExtensionManifests));

$_SESSION['_raven_extension_permission_masks'] = $extensionPermissionCatalog;
$_SESSION['_raven_enabled_extensions'] = array_keys($enabledExtensions);

// Build dedicated nav links by extension type.
$extensionNavItems = [];
$moduleNavItems = [];
$systemExtensionNavItems = [];
foreach ($enabledExtensionManifests as $directoryName => $manifest) {
    $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
    if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
        $type = 'plugin';
    }

    $panelRoutesFile = $app['root'] . '/private/ext/' . $directoryName . '/lib/routes_panel.php';
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
        $systemExtensionNavItems[] = $item;
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
    foreach ($app['channels']->listOptions() as $channelOption) {
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

$router = new Router();

// Authentication routes.
// These remain intentionally small wrappers so auth logic stays centralized
// inside AuthController and can be reused by any future panel entrypoints.
$router->add('GET', '/login', static function () use ($authController): void {
    $authController->showLogin();
});

$router->add('POST', '/login', static function () use ($authController): void {
    $authController->login($_POST);
});

$router->add('GET', '/login/2fa', static function () use ($authController): void {
    $authController->showLoginTwoFactor();
});

$router->add('POST', '/login/2fa', static function () use ($authController): void {
    $authController->loginTwoFactor($_POST);
});

$router->add('POST', '/login/2fa/select', static function () use ($authController): void {
    $authController->loginTwoFactorSelect($_POST);
});

$router->add('POST', '/login/2fa/webauthn/options', static function () use ($authController): void {
    $authController->loginTwoFactorWebauthnOptions($_POST);
});

$router->add('POST', '/login/2fa/webauthn/verify', static function () use ($authController): void {
    $authController->loginTwoFactorWebauthnVerify($_POST);
});

$router->add('POST', '/logout', static function () use ($authController): void {
    $authController->logout($_POST);
});

// Dashboard route.
// Serves the panel landing page after access checks.
$router->add('GET', '/', static function () use ($panelController): void {
    $panelController->dashboard();
});

// Pages routes.
// Includes list/create/edit/save plus gallery media and delete actions.
$router->add('GET', '/pages', static function () use ($panelController): void {
    $panelController->pagesList();
});

$router->add('GET', '/pages/edit', static function () use ($panelController): void {
    $panelController->pagesEdit(null);
});

$router->add('GET', '/pages/edit/{id}', static function (array $params) use ($panelController, $app): void {
    $id = $app['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelController->pagesEdit($id);
});

$router->add('POST', '/pages/save', static function () use ($panelController): void {
    $panelController->pagesSave($_POST);
});

// Uploads one image into a page gallery (Media tab action).
$router->add('POST', '/pages/gallery/upload', static function () use ($panelController): void {
    $panelController->pagesGalleryUpload($_POST, $_FILES);
});

// Deletes one gallery image from a page (Media tab action).
$router->add('POST', '/pages/gallery/delete', static function () use ($panelController): void {
    $panelController->pagesGalleryDelete($_POST);
});

// Deletes one page from the Pages index action column.
$router->add('POST', '/pages/delete', static function () use ($panelController): void {
    $panelController->pagesDelete($_POST);
});

// Channel routes (list + edit + save + delete).
// Channel CRUD remains in the panel controller while routing stays declarative.
$router->add('GET', '/channels', static function () use ($panelController): void {
    $panelController->channelsList();
});

$router->add('GET', '/channels/edit', static function () use ($panelController): void {
    $panelController->channelsEdit(null);
});

$router->add('GET', '/channels/edit/{id}', static function (array $params) use ($panelController, $app): void {
    $id = $app['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelController->channelsEdit($id);
});

$router->add('POST', '/channels/save', static function () use ($panelController): void {
    $panelController->channelsSave($_POST, $_FILES);
});

$router->add('POST', '/channels/delete', static function () use ($panelController): void {
    $panelController->channelsDelete($_POST);
});

// Category/Tag/Redirect/User/Group routes.
// Kept explicit (instead of dynamic routing) for clarity and predictable auth gates.

if ($categoryEnabled) {
    $router->add('GET', '/categories', static function () use ($panelController): void {
        $panelController->categoriesList();
    });

    $router->add('GET', '/categories/edit', static function () use ($panelController): void {
        $panelController->categoriesEdit(null);
    });

    $router->add('GET', '/categories/edit/{id}', static function (array $params) use ($panelController, $app): void {
        $id = $app['input']->int($params['id'] ?? null, 1);

        if ($id === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $panelController->categoriesEdit($id);
    });

    $router->add('POST', '/categories/save', static function () use ($panelController): void {
        $panelController->categoriesSave($_POST, $_FILES);
    });

    $router->add('POST', '/categories/delete', static function () use ($panelController): void {
        $panelController->categoriesDelete($_POST);
    });
}

if ($tagEnabled) {
    $router->add('GET', '/tags', static function () use ($panelController): void {
        $panelController->tagsList();
    });

    $router->add('GET', '/tags/edit', static function () use ($panelController): void {
        $panelController->tagsEdit(null);
    });

    $router->add('GET', '/tags/edit/{id}', static function (array $params) use ($panelController, $app): void {
        $id = $app['input']->int($params['id'] ?? null, 1);

        if ($id === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $panelController->tagsEdit($id);
    });

    $router->add('POST', '/tags/save', static function () use ($panelController): void {
        $panelController->tagsSave($_POST, $_FILES);
    });

    $router->add('POST', '/tags/delete', static function () use ($panelController): void {
        $panelController->tagsDelete($_POST);
    });
}

$router->add('GET', '/redirects', static function () use ($panelController): void {
    $panelController->redirectsList();
});

$router->add('GET', '/redirects/edit', static function () use ($panelController): void {
    $panelController->redirectsEdit(null);
});

$router->add('GET', '/redirects/edit/{id}', static function (array $params) use ($panelController, $app): void {
    $id = $app['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelController->redirectsEdit($id);
});

$router->add('POST', '/redirects/save', static function () use ($panelController): void {
    $panelController->redirectsSave($_POST);
});

$router->add('POST', '/redirects/delete', static function () use ($panelController): void {
    $panelController->redirectsDelete($_POST);
});

$router->add('GET', '/users', static function () use ($panelController): void {
    $panelController->usersList();
});

$router->add('GET', '/users/edit', static function () use ($panelController): void {
    $panelController->usersEdit(null);
});

$router->add('GET', '/users/edit/{id}', static function (array $params) use ($panelController, $app): void {
    $id = $app['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelController->usersEdit($id);
});

$router->add('POST', '/users/save', static function () use ($panelController): void {
    $panelController->usersSave($_POST, $_FILES);
});

$router->add('POST', '/users/delete', static function () use ($panelController): void {
    $panelController->usersDelete($_POST);
});

$router->add('GET', '/users/invites', static function () use ($panelController): void {
    $panelController->userInvites();
});

$router->add('POST', '/users/invites/create', static function () use ($panelController): void {
    $panelController->userInvitesCreate($_POST);
});

$router->add('POST', '/users/invites/generate', static function () use ($panelController): void {
    $panelController->userInvitesGenerate($_POST);
});

$router->add('POST', '/users/invites/delete', static function () use ($panelController): void {
    $panelController->userInvitesDelete($_POST);
});

$router->add('GET', '/groups', static function () use ($panelController): void {
    $panelController->groupsList();
});

$router->add('GET', '/groups/edit', static function () use ($panelController): void {
    $panelController->groupsEdit(null);
});

$router->add('GET', '/groups/edit/{id}', static function (array $params) use ($panelController, $app): void {
    $id = $app['input']->int($params['id'] ?? null, 1);

    if ($id === null) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $panelController->groupsEdit($id);
});

$router->add('POST', '/groups/save', static function () use ($panelController): void {
    $panelController->groupsSave($_POST);
});

$router->add('POST', '/groups/delete', static function () use ($panelController): void {
    $panelController->groupsDelete($_POST);
});

$router->add('GET', '/preferences', static function () use ($panelController): void {
    $panelController->preferences();
});

$router->add('POST', '/preferences/save', static function () use ($panelController): void {
    $panelController->preferencesSave($_POST, $_FILES);
});

$router->add('POST', '/preferences/2fa/totp/setup', static function () use ($panelController): void {
    $panelController->preferencesTotpSetup($_POST);
});

$router->add('POST', '/preferences/2fa/recovery/generate', static function () use ($panelController): void {
    $panelController->preferencesRecoveryCodeGenerate($_POST);
});

$router->add('POST', '/preferences/2fa/webauthn/options', static function () use ($panelController): void {
    $panelController->preferencesWebauthnCreateOptions($_POST);
});

$router->add('POST', '/preferences/2fa/webauthn/register', static function () use ($panelController): void {
    $panelController->preferencesWebauthnRegister($_POST);
});

// Configuration routes.
// Configuration editing is restricted to Super Admin capability.
$router->add('GET', '/configuration', static function () use ($panelController): void {
    $panelController->configuration();
});

$router->add('POST', '/configuration/save', static function () use ($panelController): void {
    $panelController->configurationSave($_POST);
});

// Routing inventory route.
$router->add('GET', '/routing', static function () use ($panelController): void {
    $panelController->routing();
});

$router->add('GET', '/routing/export', static function () use ($panelController): void {
    $panelController->routingExport();
});

// Public Theme manager routes.
$router->add('GET', '/themes', static function () use ($panelController): void {
    $panelController->themes();
});

$router->add('POST', '/themes/enable', static function () use ($panelController): void {
    $panelController->themesEnable($_POST);
});

$router->add('POST', '/themes/create', static function () use ($panelController): void {
    $panelController->themesCreate($_POST);
});

$router->add('POST', '/themes/upload', static function () use ($panelController): void {
    $panelController->themesUpload($_POST, $_FILES);
});

$router->add('GET', '/themes/export', static function () use ($panelController): void {
    $panelController->themesExport($_GET);
});

$router->add('POST', '/themes/delete', static function () use ($panelController): void {
    $panelController->themesDelete($_POST);
});

// Extensions management routes (placeholder system foundation for future plugin runtime wiring).
$router->add('GET', '/extensions', static function () use ($panelController): void {
    $panelController->extensions();
});

$router->add('POST', '/extensions/toggle', static function () use ($panelController): void {
    $panelController->extensionsToggle($_POST);
});

$router->add('POST', '/extensions/upload', static function () use ($panelController): void {
    $panelController->extensionsUpload($_POST, $_FILES);
});

$router->add('GET', '/extensions/export', static function () use ($panelController): void {
    $panelController->extensionsExport($_GET);
});

$router->add('POST', '/extensions/create', static function () use ($panelController): void {
    $panelController->extensionsCreate($_POST);
});

$router->add('POST', '/extensions/delete', static function () use ($panelController): void {
    $panelController->extensionsDelete($_POST);
});

$router->add('POST', '/extensions/permission', static function () use ($panelController): void {
    $panelController->extensionsPermission($_POST);
});

// Extension route registration.
//
// Each enabled extension can optionally provide `private/ext/{name}/lib/routes_panel.php`
// that returns a callable with signature:
//   function (Router $router, array $context): void
//
// Context keys:
// - app: container array from bootstrap
// - panelUrl: callable(string): string for building panel-prefixed links
// - requirePanelLogin: callable(): void guard for panel access
// - currentUserTheme: callable(): string current panel theme slug
// - renderPublicNotFound: callable(): void themed public 404 responder
// - extensionDirectory: enabled extension folder name
// - extensionRequiredPermissionBit: required default extension permission bit
// - extensionPermissionOptions: extension-level permission options map (`bit => label`)
// - extensionPermissionBits: extension-level bit map (`key => bit`)
// - extensionPermissionLevels: extension-level rows from manifest
// - extensionDefaultPermissionLevel: default extension-level key
// - requireExtensionPermission: callable(?string $levelKey = null): void
foreach (array_keys($enabledExtensions) as $extensionName) {
    $routesFile = $app['root'] . '/private/ext/' . $extensionName . '/lib/routes_panel.php';
    if (!is_file($routesFile)) {
        continue;
    }

    /** @var mixed $registrar */
    $registrar = require $routesFile;
    if (!is_callable($registrar)) {
        continue;
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
        $extensionRequirePanelAccess = static function () use ($requirePanelLoginForExtension, $app, $panelController): void {
            $requirePanelLoginForExtension();
            if (!$app['auth']->hasPanelPermissionBit(PanelAccess::CONFIGURATION_VIEW)) {
                $panelController->renderPublicNotFound();
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
                $panelController->renderPublicNotFound();
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
            $panelController->renderPublicNotFound();
            exit;
        }
    };

    $registrar($router, [
        'app' => $app,
        'panelUrl' => $panelUrl,
        'requirePanelLogin' => $extensionRequirePanelAccess,
        'requireExtensionPermission' => $requireExtensionPermission,
        'currentUserTheme' => $currentUserTheme,
        'renderPublicNotFound' => static function () use ($panelController): void {
            $panelController->renderPublicNotFound();
        },
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
$debugToolbarSettings = DebugToolbarConfigResolver::fromConfig($app['config']);
$debugToolbarEnabled = false;
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
$canRenderPanelDebugToolbar = static function () use ($app, $isPanelAuthHelperInternalPath, $internalPath): bool {
    if (!isset($app['auth']) || $isPanelAuthHelperInternalPath($internalPath)) {
        return false;
    }

    $userId = $app['auth']->userId();
    if ($userId === null || !$app['auth']->canManageConfiguration($userId)) {
        return false;
    }

    return $app['auth']->isTwoFactorVerifiedForUser($userId);
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
    ob_start(static function (string $body) use ($app, $debugToolbarSettings, $internalPath, $method, $canRenderPanelDebugToolbar): string {
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
    $panelController->renderPublicNotFound();
}
