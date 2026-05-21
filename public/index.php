<?php

/**
 * RAVEN CMS
 * ~/public/index.php
 * Public web entry orchestration and dispatch.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Core\Debug\OutputProfilerPolicy;
use Raven\Core\Debug\OutputProfiler;
use Raven\Core\Runtime\Public\RuntimeContract as PublicRuntimeContract;
use Raven\Core\Runtime\RuntimeAssert;
use Raven\Core\Router\RouteRequest;
use Raven\Core\Router\Public\PublicRouter;
use Raven\Lib\Transport\Request as HttpRequest;
use Raven\Core\Runtime\Public\RuntimeBuilder;
use Raven\Core\Router\Public\PublicPayload;
use Raven\Core\Router\Public\PublicPolicy;
use Raven\Lib\Scheduler\Cron;

$root = dirname(__DIR__);
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = $requestPath === '' ? '/' : $requestPath;
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
if ($scriptName === '' || $scriptName[0] !== '/') {
    $scriptName = '/' . ltrim($scriptName, '/');
}
$mountBasePath = dirname($scriptName);
if ($mountBasePath === '.' || $mountBasePath === '/' || $mountBasePath === '\\') {
    $mountBasePath = '';
}
$installPath = ($mountBasePath !== '' ? $mountBasePath : '') . '/install.php';

/**
 * Installer handoff:
 * - If runtime config is missing and install lock is absent, redirect to installer.
 * - If request explicitly targets installer, run installer script directly.
 */
$configPath = $root . '/private/dat/config.php';
$installLockPath = $root . '/private/dat/install.lock';

if (!is_file($configPath)) {
    if (!is_file($installLockPath)) {
        header('Location: ' . $installPath, true, 302);
        exit;
    }

    http_response_code(500);
    echo 'Raven configuration file is missing.';
    exit;
}

if ($requestPath === $installPath) {
    require $root . '/public/install.php';
    exit;
}

/**
 * Early panel handoff:
 * When the web server routes all requests through this public front
 * controller, forward panel-prefixed URLs into `panel/index.php`.
 */
$rawConfig = require $configPath;
$configuredPanelPath = trim((string) ($rawConfig['panel']['path'] ?? 'panel'), '/');
$configuredPanelPrefix = '/' . $configuredPanelPath;
if ($configuredPanelPath !== '' && ($requestPath === $configuredPanelPrefix || str_starts_with($requestPath, $configuredPanelPrefix . '/'))) {
    require $root . '/panel/index.php';
    exit;
}

require_once $root . '/private/Raven.php';
/** @var array<string, mixed> $rvn */
$rvn = \Raven\Raven::boot();
$rvn = RuntimeBuilder::build($rvn);
PublicRuntimeContract::assert($rvn);

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$profilerSettings = OutputProfilerPolicy::fromConfig($rvn['config']);
$isPublicAuthHelperPath = static function (string $path) use ($requestPath): bool {
    $normalized = trim($path !== '' ? $path : $requestPath);
    $normalized = (string) parse_url($normalized, PHP_URL_PATH);
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
        '/register',
    ], true);
};
$canRenderPublicProfiler = static function () use ($rvn, $isPublicAuthHelperPath, $requestPath): bool {
    if ($isPublicAuthHelperPath($requestPath)) {
        return false;
    }

    // Resolve the auth service lazily — the container may hold a closure until first use.
    $auth = $rvn['auth'] ?? null;
    if (is_callable($auth)) {
        $auth = $auth();
    }

    if (!$auth instanceof \Raven\Lib\Auth\AuthService) {
        return false;
    }

    $userId = $auth->userId();
    if ($userId === null || !$auth->panelService()->canManageConfiguration($userId)) {
        return false;
    }

    return $auth->isTwoFactorVerifiedForUser($userId);
};

OutputProfiler::arm(
    [
        'show_benchmarks' => (bool) ($profilerSettings['show_benchmarks'] ?? true),
        'show_queries' => (bool) ($profilerSettings['show_queries'] ?? true),
        'show_stack_trace' => (bool) ($profilerSettings['show_stack_trace'] ?? true),
        'show_request' => (bool) ($profilerSettings['show_request'] ?? true),
        'show_environment' => (bool) ($profilerSettings['show_environment'] ?? true),
    ],
    'public',
    $requestMethod,
    $requestPath,
    (bool) ($profilerSettings['show_on_public'] ?? false),
    $canRenderPublicProfiler
);

/**
 * Resolves one required public runtime factory from the built payload.
 */
$requirePublicFactory = static function (string $key) use ($rvn): callable {
    return RuntimeAssert::requireCallable($rvn, $key, 'public');
};

/** @var callable(): object $publicPageController */
$publicPageController = $requirePublicFactory('public_page_controller');
/** @var callable(): object $publicAuthController */
$publicAuthController = $requirePublicFactory('public_auth_controller');
/** @var callable(): object $publicProfileController */
$publicProfileController = $requirePublicFactory('public_profile_controller');
/** @var callable(): object $publicCategoryController */
$publicCategoryController = $requirePublicFactory('public_category_controller');
/** @var callable(): object $publicChannelController */
$publicChannelController = $requirePublicFactory('public_channel_controller');
/** @var callable(): object $publicGroupController */
$publicGroupController = $requirePublicFactory('public_group_controller');
/** @var callable(): object $publicFeedController */
$publicFeedController = $requirePublicFactory('public_feed_controller');
/** @var callable(): object $publicTagController */
$publicTagController = $requirePublicFactory('public_tag_controller');
/** @var callable(): object $publicRequestContext */
$publicRequestContext = $requirePublicFactory('public_request_context');
/** @var callable(): array<string, mixed> $initializePublicRuntime */
$initializePublicRuntime = $requirePublicFactory('initialize_public_runtime');

// Mirror the panel's two-phase bootstrap: skip the heavy init (extension services
// priming, public_site_data population) for auth-helper paths that never need them.
$shouldInitializePublicRuntime = !$isPublicAuthHelperPath($requestPath);
if ($shouldInitializePublicRuntime) {
    $rvn = $initializePublicRuntime();
}

$input = $rvn['input'];
$routeConfig = PublicPolicy::build($rvn['config'], $input);
$routeDeps = new PublicPayload(
    $rvn,
    $publicAuthController,
    $publicPageController,
    $publicProfileController,
    $publicCategoryController,
    $publicChannelController,
    $publicGroupController,
    $publicFeedController,
    $publicTagController,
    $publicRequestContext,
    $input,
    $routeConfig
);
$router = new PublicRouter();
$router->register($routeDeps);

$method = $requestMethod;
$path = HttpRequest::path();

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$bypassAvailabilityPaths = is_array($routeConfig['bypass_availability_paths'] ?? null)
    ? $routeConfig['bypass_availability_paths']
    : [];
$bypassAvailability = in_array($path, $bypassAvailabilityPaths, true);

if (!$bypassAvailability && !$publicRequestContext()->enforceSiteAvailability()) {
    exit;
}

$dispatchResult = $router->dispatch(new RouteRequest($method, $path));
if (!$dispatchResult->isHandled()) {
    $publicRequestContext()->notFound();
}

Cron::runIfDue(
    $rvn,
    $root,
    $rvn['config']->get('site.scheduler', 'always') === 'always'
);
