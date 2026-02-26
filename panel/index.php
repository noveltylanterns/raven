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
use Raven\Core\Debug\RequestProfiler;
use Raven\Core\Routing\Router;

use function Raven\Core\Support\request_path;
use function Raven\Core\Support\redirect;

/**
 * Panel front controller for https://{domain}/{panel_path}/
 */
$app = require dirname(__DIR__) . '/private/bootstrap.php';

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
    $app['contact_forms'],
    $app['signup_forms'],
    $app['tags'],
    $app['taxonomy'],
    $app['users']
);

/**
 * Normalizes request path into panel-internal path.
 *
 * This supports both:
 * - direct `/panel/...` style requests
 * - alias-mounted `/{panel_path}/...` requests
 */
$requestedPath = request_path();
$configuredPanelPrefix = '/' . trim((string) $app['config']->get('panel.path', 'panel'), '/');
$legacyPanelPrefix = '/panel';

$internalPath = $requestedPath;

if ($requestedPath === $configuredPanelPrefix) {
    $internalPath = '/';
} elseif (str_starts_with($requestedPath, $configuredPanelPrefix . '/')) {
    $internalPath = substr($requestedPath, strlen($configuredPanelPrefix));
} elseif ($requestedPath === $legacyPanelPrefix) {
    $internalPath = '/';
} elseif (str_starts_with($requestedPath, $legacyPanelPrefix . '/')) {
    $internalPath = substr($requestedPath, strlen($legacyPanelPrefix));
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
    $prefix = '/' . trim((string) $app['config']->get('panel.path', 'panel'), '/');
    $suffix = '/' . ltrim($suffix, '/');
    return rtrim($prefix, '/') . ($suffix === '/' ? '' : $suffix);
};

/**
 * Returns extension-assignable panel-side permission options.
 *
 * @return array<int, string>
 */
$extensionPanelPermissionOptions = static function (): array {
    return [
        PanelAccess::PANEL_LOGIN => 'Access Dashboard',
        PanelAccess::MANAGE_CONTENT => 'Manage Content',
        PanelAccess::MANAGE_TAXONOMY => 'Manage Taxonomy',
        PanelAccess::MANAGE_USERS => 'Manage Users',
        PanelAccess::MANAGE_GROUPS => 'Manage Groups',
        PanelAccess::MANAGE_CONFIGURATION => 'Manage System Configuration',
    ];
};

/**
 * Returns enabled extension directory map from `private/ext/.state.php`.
 *
 * Falls back to `private/ext/.state.php.dist` when runtime state file does not
 * exist yet (for example before installer initialization).
 *
 * @return array<string, bool>
 */
$loadEnabledExtensionState = static function () use ($app): array {
    $statePath = $app['root'] . '/private/ext/.state.php';
    $templatePath = $app['root'] . '/private/ext/.state.php.dist';
    $sourcePath = is_file($statePath) ? $statePath : $templatePath;
    if (!is_file($sourcePath)) {
        return [];
    }

    // Read the latest state immediately after panel toggles.
    clearstatcache(true, $sourcePath);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($sourcePath, true);
    }

    /** @var mixed $rawState */
    $rawState = require $sourcePath;
    if (!is_array($rawState)) {
        return [];
    }

    /** @var mixed $rawEnabled */
    $rawEnabled = array_key_exists('enabled', $rawState) ? $rawState['enabled'] : $rawState;
    if (!array_key_exists('enabled', $rawState) && array_key_exists('permissions', $rawState)) {
        $rawEnabled = [];
    }
    if (!is_array($rawEnabled)) {
        return [];
    }

    $enabled = [];
    foreach ($rawEnabled as $directory => $flag) {
        if (!is_string($directory) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directory)) {
            continue;
        }

        if ((bool) $flag) {
            $enabled[$directory] = true;
        }
    }

    return $enabled;
};

/**
 * Returns required panel-side permission bit per extension from state.
 *
 * @return array<string, int>
 */
$loadExtensionPermissionState = static function () use ($app, $extensionPanelPermissionOptions): array {
    $statePath = $app['root'] . '/private/ext/.state.php';
    $templatePath = $app['root'] . '/private/ext/.state.php.dist';
    $sourcePath = is_file($statePath) ? $statePath : $templatePath;
    if (!is_file($sourcePath)) {
        return [];
    }

    clearstatcache(true, $sourcePath);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($sourcePath, true);
    }

    /** @var mixed $rawState */
    $rawState = require $sourcePath;
    if (!is_array($rawState)) {
        return [];
    }

    /** @var mixed $rawPermissions */
    $rawPermissions = $rawState['permissions'] ?? [];
    if (!is_array($rawPermissions)) {
        return [];
    }

    $allowedBits = array_keys($extensionPanelPermissionOptions());
    $permissions = [];
    foreach ($rawPermissions as $directory => $rawBit) {
        if (!is_string($directory) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directory)) {
            continue;
        }

        $bit = (int) $rawBit;
        if (!in_array($bit, $allowedBits, true)) {
            continue;
        }

        $permissions[$directory] = $bit;
    }

    return $permissions;
};

/**
 * Reads minimal extension manifest metadata from one extension directory.
 *
 * @return array{
 *   name: string,
 *   type: string,
 *   panel_path: string,
 *   panel_section: string,
 *   system_extension: bool
 * }|null
 */
$readExtensionManifest = static function (string $directoryName) use ($app): ?array {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directoryName)) {
        return null;
    }

    $manifestPath = $app['root'] . '/private/ext/' . $directoryName . '/extension.json';
    if (!is_file($manifestPath)) {
        return null;
    }

    $raw = file_get_contents($manifestPath);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $name = trim((string) ($decoded['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? 'basic')));
    if (!in_array($type, ['basic', 'system'], true)) {
        $type = 'basic';
    }

    $panelPath = trim((string) ($decoded['panel_path'] ?? ''), '/');
    if ($panelPath !== '' && preg_match('/^[a-z0-9][a-z0-9_\/-]*$/i', $panelPath) !== 1) {
        $panelPath = '';
    }

    $panelSection = strtolower(trim((string) ($decoded['panel_section'] ?? '')));
    if ($panelSection !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $panelSection) !== 1) {
        $panelSection = '';
    }

    $systemExtension = (bool) ($decoded['system_extension'] ?? false);

    return [
        'name' => $name,
        'type' => $type,
        'panel_path' => $panelPath,
        'panel_section' => $panelSection,
        'system_extension' => $systemExtension,
    ];
};

/**
 * Returns true when one extension is enabled and has a valid manifest.
 */
$isExtensionEnabled = static function (string $directoryName) use ($loadEnabledExtensionState, $readExtensionManifest, $app): bool {
    $enabledState = $loadEnabledExtensionState();
    if (empty($enabledState[$directoryName])) {
        return false;
    }

    // Invalid extensions must never execute.
    if ($readExtensionManifest($directoryName) === null) {
        return false;
    }

    return is_dir($app['root'] . '/private/ext/' . $directoryName);
};

/**
 * Synchronizes lightweight identity data for the personalized welcome heading.
 */
$syncPanelIdentity = static function () use ($app): void {
    $userId = $app['auth']->userId();
    if ($userId === null) {
        unset($_SESSION['raven_panel_identity']);
        unset($_SESSION['_raven_can_manage_content']);
        unset($_SESSION['_raven_can_manage_taxonomy']);
        unset($_SESSION['_raven_can_manage_users']);
        unset($_SESSION['_raven_can_manage_groups']);
        unset($_SESSION['_raven_can_manage_configuration']);
        return;
    }

    $preferences = $app['auth']->userPreferences($userId);
    if ($preferences === null) {
        unset($_SESSION['raven_panel_identity']);
        unset($_SESSION['_raven_can_manage_content']);
        unset($_SESSION['_raven_can_manage_taxonomy']);
        unset($_SESSION['_raven_can_manage_users']);
        unset($_SESSION['_raven_can_manage_groups']);
        unset($_SESSION['_raven_can_manage_configuration']);
        return;
    }

    $_SESSION['raven_panel_identity'] = [
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
 * Returns true when current request targets direct panel root/login paths.
 */
$isGuestPanelLoginEntryInternalPath = static function () use ($internalPath): bool {
    $path = '/' . ltrim($internalPath, '/');
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return in_array($path, ['/', '/login'], true);
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

    $syncPanelIdentity();
};

/**
 * Returns panel theme value for current user.
 */
$defaultPanelTheme = static function () use ($app): string {
    $theme = strtolower(trim((string) $app['config']->get('panel.default_theme', 'light')));
    if (!in_array($theme, ['light', 'dark'], true)) {
        return 'light';
    }

    return $theme;
};

/**
 * Returns panel theme value for current user.
 */
$currentUserTheme = static function () use ($app, $defaultPanelTheme): string {
    $theme = $defaultPanelTheme();
    $userId = $app['auth']->userId();
    if ($userId !== null) {
        $prefs = $app['auth']->userPreferences($userId);
        $candidate = strtolower(trim((string) ($prefs['theme'] ?? 'default')));
        if (in_array($candidate, ['default', 'light', 'dark'], true)) {
            $theme = $candidate === 'default' ? $defaultPanelTheme() : $candidate;
        }
    }

    return $theme;
};

/**
 * Returns true when current user has one panel-side permission bit.
 */
$hasPanelPermissionBit = static function (int $bit) use ($app): bool {
    return match ($bit) {
        PanelAccess::PANEL_LOGIN => $app['auth']->canAccessPanel(),
        PanelAccess::MANAGE_CONTENT => $app['auth']->canManageContent(),
        PanelAccess::MANAGE_TAXONOMY => $app['auth']->canManageTaxonomy(),
        PanelAccess::MANAGE_USERS => $app['auth']->canManageUsers(),
        PanelAccess::MANAGE_GROUPS => $app['auth']->canManageGroups(),
        PanelAccess::MANAGE_CONFIGURATION => $app['auth']->canManageConfiguration(),
        default => false,
    };
};

// Compute the enabled extension list once and expose it for panel layout/nav logic.
$enabledExtensions = [];
$enabledExtensionManifests = [];
$enabledState = $loadEnabledExtensionState();
$extensionPermissionState = $loadExtensionPermissionState();
$_SESSION['_raven_extension_permission_masks'] = $extensionPermissionState;
foreach (array_keys($enabledState) as $directoryName) {
    if ($isExtensionEnabled($directoryName)) {
        $enabledExtensions[$directoryName] = true;

        $manifest = $readExtensionManifest($directoryName);
        if ($manifest !== null) {
            $enabledExtensionManifests[$directoryName] = $manifest;
        }
    }
}
$_SESSION['_raven_enabled_extensions'] = array_keys($enabledExtensions);

// Build dedicated nav links by extension type.
$systemExtensionDirectories = ['database'];
$extensionNavItems = [];
$systemExtensionNavItems = [];
foreach ($enabledExtensionManifests as $directoryName => $manifest) {
    $type = strtolower(trim((string) ($manifest['type'] ?? 'basic')));
    $isSystemType = $type === 'system'
        || !empty($manifest['system_extension'])
        || in_array($directoryName, $systemExtensionDirectories, true);
    $requiredPermissionBit = (int) ($extensionPermissionState[$directoryName] ?? PanelAccess::PANEL_LOGIN);
    $allowedPermissionBits = array_keys($extensionPanelPermissionOptions());
    if (!in_array($requiredPermissionBit, $allowedPermissionBits, true)) {
        $requiredPermissionBit = PanelAccess::PANEL_LOGIN;
    }

    $panelPath = trim((string) ($manifest['panel_path'] ?? ''));
    if ($panelPath === '') {
        $panelPath = $directoryName;
    }

    $panelSection = trim((string) ($manifest['panel_section'] ?? ''));
    if ($panelSection === '') {
        $panelSection = $directoryName;
    }

    $item = [
        'label' => (string) $manifest['name'],
        'path' => $panelUrl('/' . ltrim($panelPath, '/')),
        'section' => $panelSection,
    ];

    if ($isSystemType) {
        $systemExtensionNavItems[] = $item;
        continue;
    }

    if (!$hasPanelPermissionBit($requiredPermissionBit)) {
        continue;
    }

    $extensionNavItems[] = $item;
}

usort($extensionNavItems, static function (array $a, array $b): int {
    return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
});
usort($systemExtensionNavItems, static function (array $a, array $b): int {
    return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
});

$_SESSION['_raven_nav_extensions'] = $extensionNavItems;
$_SESSION['_raven_nav_system_extensions'] = $systemExtensionNavItems;

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

// Update System routes.
$router->add('GET', '/updates', static function () use ($panelController): void {
    $panelController->updates();
});

$router->add('POST', '/updates/check', static function () use ($panelController): void {
    $panelController->updatesCheck($_POST);
});

$router->add('POST', '/updates/dry-run', static function () use ($panelController): void {
    $panelController->updatesDryRun($_POST);
});

$router->add('POST', '/updates/run', static function () use ($panelController): void {
    $panelController->updatesRun($_POST);
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
// Each enabled extension can optionally provide `private/ext/{name}/panel_routes.php`
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
// - extensionRequiredPermissionBit: required panel-side permission bit
// - extensionPermissionOptions: panel-side permission options map
// - setExtensionPermissionPath: route for updating extension permission bit
foreach (array_keys($enabledExtensions) as $extensionName) {
    $routesFile = $app['root'] . '/private/ext/' . $extensionName . '/panel_routes.php';
    if (!is_file($routesFile)) {
        continue;
    }

    /** @var mixed $registrar */
    $registrar = require $routesFile;
    if (!is_callable($registrar)) {
        continue;
    }

    $manifest = $enabledExtensionManifests[$extensionName] ?? $readExtensionManifest($extensionName);
    if (!is_array($manifest)) {
        $manifest = [
            'type' => 'basic',
            'system_extension' => false,
        ];
    }
    $type = strtolower(trim((string) ($manifest['type'] ?? 'basic')));
    $isSystemType = $type === 'system'
        || !empty($manifest['system_extension'])
        || in_array($extensionName, $systemExtensionDirectories, true);
    $requiredPermissionBit = (int) ($extensionPermissionState[$extensionName] ?? PanelAccess::PANEL_LOGIN);
    $allowedPermissionBits = array_keys($extensionPanelPermissionOptions());
    if (!in_array($requiredPermissionBit, $allowedPermissionBits, true)) {
        $requiredPermissionBit = PanelAccess::PANEL_LOGIN;
    }

    $extensionRequirePanelAccess = $requirePanelLoginForExtension;
    if ($isSystemType) {
        $extensionRequirePanelAccess = static function () use ($requirePanelLoginForExtension, $app, $panelController): void {
            $requirePanelLoginForExtension();
            if (!$app['auth']->canManageConfiguration()) {
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
            if (!$hasPanelPermissionBit($requiredPermissionBit)) {
                $panelController->renderPublicNotFound();
                exit;
            }
        };
    }

    $registrar($router, [
        'app' => $app,
        'panelUrl' => $panelUrl,
        'requirePanelLogin' => $extensionRequirePanelAccess,
        'currentUserTheme' => $currentUserTheme,
        'renderPublicNotFound' => static function () use ($panelController): void {
            $panelController->renderPublicNotFound();
        },
        'extensionDirectory' => $extensionName,
        'extensionRequiredPermissionBit' => $requiredPermissionBit,
        'extensionPermissionOptions' => $extensionPanelPermissionOptions(),
        'setExtensionPermissionPath' => $panelUrl('/extensions/permission'),
    ]);
}

$method = $requestMethod;
$parseDebugBool = static function (mixed $value, bool $default): bool {
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((int) $value) !== 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
    }

    return $default;
};
$debugToolbarSettings = [
    'show_on_public' => $parseDebugBool($app['config']->get('debug.show_on_public', false), false),
    'show_on_panel' => $parseDebugBool($app['config']->get('debug.show_on_panel', false), false),
    'show_benchmarks' => $parseDebugBool($app['config']->get('debug.show_benchmarks', true), true),
    'show_queries' => $parseDebugBool($app['config']->get('debug.show_queries', true), true),
    'show_stack_trace' => $parseDebugBool($app['config']->get('debug.show_stack_trace', true), true),
    'show_request' => $parseDebugBool($app['config']->get('debug.show_request', true), true),
    'show_environment' => $parseDebugBool($app['config']->get('debug.show_environment', true), true),
];
$debugToolbarEnabled = false;

if (
    $method === 'GET'
    && isset($app['auth'])
    && $app['auth']->canManageConfiguration()
) {
    if ($debugToolbarSettings['show_on_panel']) {
        RequestProfiler::start((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 'panel');
        RequestProfiler::enable();
        $debugToolbarEnabled = true;
    }
}

if ($debugToolbarEnabled) {
    ob_start(static function (string $body) use ($app, $debugToolbarSettings, $internalPath, $method): string {
        if (!RequestProfiler::isEnabled() || !DebugToolbarRenderer::isHtmlResponseCandidate($body)) {
            return $body;
        }

        // Defense-in-depth: always re-check current auth permission before rendering.
        if (!isset($app['auth']) || !$app['auth']->canManageConfiguration()) {
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
if (!$router->dispatch($method, $internalPath)) {
    $panelController->renderPublicNotFound();
}
