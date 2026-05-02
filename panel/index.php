<?php

/**
 * RAVEN CMS
 * ~/panel/index.php
 * Admin panel entry orchestration and dispatch.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Debug\OutputProfilerPolicy;
use Raven\Core\Debug\OutputProfiler;
use Raven\Core\Controller\Panel\SharedController;
use Raven\Core\Factory\Panel\RuntimeContract as PanelRuntimeContract;
use Raven\Core\Factory\RuntimePayloadAssert;
use Raven\Core\Router\RouteRequest;
use Raven\Core\Router\Panel\PanelRouter;
use Raven\Lib\Transport\Request as HttpRequest;
use Raven\Core\Router\Panel\PanelRuntimeBuilder;
use Raven\Core\Router\Panel\PanelRouteDeps;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Parser\PanelParser;
use Raven\Lib\Scheduler\Cron;
use Raven\Lib\View\Error as ViewError;

$root = dirname(__DIR__);
require_once $root . '/private/Raven.php';
/** @var array<string, mixed> $rvn */
$rvn = \Raven\Raven::boot();
$rvn = PanelRuntimeBuilder::build($rvn);
PanelRuntimeContract::assert($rvn);

/**
 * Resolves one required panel runtime factory from the built payload.
 */
$requirePanelFactory = static function (string $key) use ($rvn): callable {
    return RuntimePayloadAssert::requireCallable($rvn, $key, 'panel');
};

/** @var callable(): object $authController */
$authController = $requirePanelFactory('auth_controller');
/** @var callable(): object $panelDashboardController */
$panelDashboardController = $requirePanelFactory('panel_dashboard_controller');
/** @var callable(): object $panelChannelListController */
$panelChannelListController = $requirePanelFactory('panel_channel_list_controller');
/** @var callable(): object $panelChannelEditController */
$panelChannelEditController = $requirePanelFactory('panel_channel_edit_controller');
/** @var callable(): object $panelCategoryListController */
$panelCategoryListController = $requirePanelFactory('panel_category_list_controller');
/** @var callable(): object $panelCategoryEditController */
$panelCategoryEditController = $requirePanelFactory('panel_category_edit_controller');
/** @var callable(): object $panelTagListController */
$panelTagListController = $requirePanelFactory('panel_tag_list_controller');
/** @var callable(): object $panelTagEditController */
$panelTagEditController = $requirePanelFactory('panel_tag_edit_controller');
/** @var callable(): object $panelRedirectListController */
$panelRedirectListController = $requirePanelFactory('panel_redirect_list_controller');
/** @var callable(): object $panelRedirectEditController */
$panelRedirectEditController = $requirePanelFactory('panel_redirect_edit_controller');
/** @var callable(): object $panelUserListController */
$panelUserListController = $requirePanelFactory('panel_user_list_controller');
/** @var callable(): object $panelUserEditController */
$panelUserEditController = $requirePanelFactory('panel_user_edit_controller');
/** @var callable(): object $panelGroupListController */
$panelGroupListController = $requirePanelFactory('panel_group_list_controller');
/** @var callable(): object $panelGroupEditController */
$panelGroupEditController = $requirePanelFactory('panel_group_edit_controller');
/** @var callable(): object $panelPageListController */
$panelPageListController = $requirePanelFactory('panel_page_list_controller');
/** @var callable(): object $panelPageEditController */
$panelPageEditController = $requirePanelFactory('panel_page_edit_controller');
/** @var callable(): object $panelPreferencesController */
$panelPreferencesController = $requirePanelFactory('panel_preferences_controller');
/** @var callable(): object $panelConfigController */
$panelConfigController = $requirePanelFactory('panel_config_controller');
/** @var callable(): object $panelLogsController */
$panelLogsController = $requirePanelFactory('panel_logs_controller');
/** @var callable(): object $panelRoutingController */
$panelRoutingController = $requirePanelFactory('panel_routing_controller');
/** @var callable(): object $panelUpdateController */
$panelUpdateController = $requirePanelFactory('panel_update_controller');
/** @var callable(): object $panelThemeController */
$panelThemeController = $requirePanelFactory('panel_theme_controller');
/** @var callable(): object $panelExtensionController */
$panelExtensionController = $requirePanelFactory('panel_extension_controller');
/** @var callable(array<int, string>=): array<string, array<string, mixed>> $panelPermissionMapProvider */
$panelPermissionMapProvider = $requirePanelFactory('panel_permission_map_provider');
/** @var callable(): object $panelRequestContext */
$panelRequestContext = $requirePanelFactory('panel_request_context');
/** @var callable(): array<string, mixed> $initializePanelRuntime */
$initializePanelRuntime = $requirePanelFactory('initialize_panel_runtime');

/**
 * Normalizes request path into panel-internal path.
 */
$requestedPath = HttpRequest::path();
$configuredPanelPrefix = PanelParser::fromConfig($rvn['config']);

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

$categoryEnabled = ConfigParser::bool($rvn['config']->get('category.enabled', true), true);
$tagEnabled = ConfigParser::bool($rvn['config']->get('tag.enabled', true), true);
$_SESSION['_raven_category_enabled'] = $categoryEnabled;
$_SESSION['_raven_tag_enabled'] = $tagEnabled;

// Serve theme assets before panel route dispatch when front-controller rewrite is enabled.
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (SharedController::serveThemeAssetIfMatched($rvn, $internalPath, $requestMethod)) {
    return;
}

/**
 * Builds panel URL with configured prefix.
 */
$panelUrl = static function (string $suffix = '') use ($rvn): string {
    return PanelParser::fromConfig($rvn['config'], $suffix);
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
 * Resolve extension permission catalog before nav population and routing so both share one source.
 */
$extensionPermissionCatalog = [];
if ($shouldInitializeFullPanelRuntime) {
    $extensionPermissionCatalog = $panelPermissionMapProvider(array_keys($enabledExtensionManifests));
}

SharedController::populateNavSession(
    $rvn,
    $categoryEnabled,
    $tagEnabled,
    $shouldInitializeFullPanelRuntime,
    $enabledExtensions,
    $enabledExtensionManifests,
    $extensionPermissionCatalog,
    $panelUrl
);

$renderNotFound = static function () use ($panelRequestContext): void {
    $panelRequestContext()->renderPanelNotFound();
};

$renderPublicNotFound = static function () use ($rvn, $root): void {
    (new ViewError($rvn['config'], $root))->render404();
};

$router = new PanelRouter();
$routeDeps = new PanelRouteDeps(
    $rvn,
    $authController,
    $panelDashboardController,
    $panelChannelListController,
    $panelChannelEditController,
    $panelCategoryListController,
    $panelCategoryEditController,
    $panelTagListController,
    $panelTagEditController,
    $panelRedirectListController,
    $panelRedirectEditController,
    $panelUserListController,
    $panelUserEditController,
    $panelGroupListController,
    $panelGroupEditController,
    $panelPageListController,
    $panelPageEditController,
    $panelPreferencesController,
    $panelConfigController,
    $panelLogsController,
    $panelRoutingController,
    $panelUpdateController,
    $panelThemeController,
    $panelExtensionController,
    $rvn['input'],
    $categoryEnabled,
    $tagEnabled,
    $renderNotFound,
    $enabledExtensions,
    $enabledExtensionManifests,
    $extensionPermissionCatalog,
    $internalPath,
    $renderPublicNotFound
);
$router->register($routeDeps);

$method = $requestMethod;
$profilerSettings = OutputProfilerPolicy::fromConfig($rvn['config']);
$canRenderPanelProfiler = static function () use ($rvn, $isPanelAuthHelperInternalPath, $internalPath): bool {
    if (!isset($rvn['auth']) || $isPanelAuthHelperInternalPath($internalPath)) {
        return false;
    }

    $userId = $rvn['auth']->userId();
    if ($userId === null || !$rvn['auth']->canManageConfiguration($userId)) {
        return false;
    }

    return $rvn['auth']->isTwoFactorVerifiedForUser($userId);
};

OutputProfiler::arm(
    [
        'show_benchmarks' => (bool) ($profilerSettings['show_benchmarks'] ?? true),
        'show_queries' => (bool) ($profilerSettings['show_queries'] ?? true),
        'show_stack_trace' => (bool) ($profilerSettings['show_stack_trace'] ?? true),
        'show_request' => (bool) ($profilerSettings['show_request'] ?? true),
        'show_environment' => (bool) ($profilerSettings['show_environment'] ?? true),
    ],
    'panel',
    $method,
    $internalPath,
    (bool) ($profilerSettings['show_on_panel'] ?? false),
    $canRenderPanelProfiler
);

$dispatchResult = $router->dispatch(new RouteRequest($method, $internalPath));
if (!$dispatchResult->isHandled()) {
    $renderPublicNotFound();
}

Cron::runIfDue(
    $rvn,
    $root,
    in_array($rvn['config']->get('site.scheduler', 'always'), ['always', 'panel'], true),
    static function (array $runtime): array {
        if (!is_callable($runtime['boot_extensions'] ?? null)) {
            return $runtime;
        }

        /** @var callable(): array<string, mixed> $bootExtensions */
        $bootExtensions = $runtime['boot_extensions'];
        return $bootExtensions();
    }
);
