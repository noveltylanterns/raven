<?php

/**
 * RAVEN CMS
 * ~/public/index.php
 * Public web front controller for site routing.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\PublicController;
use Raven\Core\Diagnostics\DebugToolbarRenderer;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarConfigResolver;
use Raven\Lib\Diagnostics\RequestProfiler;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Lib\Routing\RouteRequest;
use Raven\Lib\Routing\Router;
use Raven\Lib\Routing\RouteConfigService;

use function Raven\Core\Support\request_path;

/**
 * Public front controller for https://{domain}/
 */
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
$configPath = dirname(__DIR__) . '/private/dat/config.php';
$installLockPath = dirname(__DIR__) . '/private/dat/install.lock';

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
    require __DIR__ . '/install.php';
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
    require dirname(__DIR__) . '/panel/index.php';
    exit;
}

$app = require dirname(__DIR__) . '/private/raven.php';

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$debugToolbarSettings = DebugToolbarConfigResolver::fromConfig($app['config']);
$debugToolbarEnabled = false;
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
$canRenderPublicDebugToolbar = static function () use ($app, $isPublicAuthHelperPath, $requestPath): bool {
    if (!isset($app['auth']) || $isPublicAuthHelperPath($requestPath)) {
        return false;
    }

    $userId = $app['auth']->userId();
    if ($userId === null || !$app['auth']->canManageConfiguration($userId)) {
        return false;
    }

    return $app['auth']->isTwoFactorVerifiedForUser($userId);
};

if (
    $requestMethod === 'GET'
    && $canRenderPublicDebugToolbar()
) {
    if ($debugToolbarSettings['show_on_public']) {
        RequestProfiler::start((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 'public');
        RequestProfiler::enable();
        $debugToolbarEnabled = true;
    }
}

if ($debugToolbarEnabled) {
    ob_start(static function (string $body) use ($app, $debugToolbarSettings, $requestPath, $requestMethod, $canRenderPublicDebugToolbar): string {
        if (!RequestProfiler::isEnabled() || !DebugToolbarRenderer::isHtmlResponseCandidate($body)) {
            return $body;
        }

        // Defense-in-depth: always re-check current auth permission before rendering.
        if (!$canRenderPublicDebugToolbar()) {
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
                'scope' => 'public',
                'can_manage_configuration' => true,
                'status_code' => http_response_code(),
                'request_method' => $requestMethod,
                'request_path' => $requestPath,
                'hostname' => (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')),
            ]
        );

        if ($toolbarHtml === '') {
            return $body;
        }

        return DebugToolbarRenderer::inject($body, $toolbarHtml);
    });
}

$controller = new PublicController(
    $app['view'],
    $app['config'],
    $app['auth'],
    $app['group'],
    $app['page_images'],
    $app['page'],
    $app['redirect'],
    $app['taxonomy_lookup'],
    $app['user'],
    $app['invite_tokens'],
    $app['input'],
    $app['csrf'],
    is_array($app['extension_services'] ?? null) ? (array) $app['extension_services'] : []
);

$input = $app['input'];
$routeConfig = new RouteConfigService($app['config'], $input);

$panelPath = (string) $app['config']->get('panel.path', 'panel');
$categoryPrefix = $routeConfig->categoryRoutePrefix();
$tagPrefix = $routeConfig->tagRoutePrefix();
$profilePrefix = $routeConfig->profileRoutePrefix();
$groupPrefix = $routeConfig->groupRoutePrefix();
$feedsEnabled = $routeConfig->feedEnabled();
$rssFeedRoute = $routeConfig->rssFeedRoute();
$atomFeedRoute = $routeConfig->atomFeedRoute();

if ($rssFeedRoute !== '' && $atomFeedRoute !== '' && $rssFeedRoute === $atomFeedRoute) {
    if ($rssFeedRoute !== 'rss') {
        $rssFeedRoute = 'rss';
    } elseif ($atomFeedRoute !== 'atom') {
        $atomFeedRoute = 'atom';
    } else {
        $atomFeedRoute = '';
    }
}

// Keep category/tag prefixes distinct even if config is manually edited to collide.
if ($categoryPrefix !== '' && $tagPrefix !== '' && $categoryPrefix === $tagPrefix) {
    if ($categoryPrefix !== 'cat') {
        $categoryPrefix = 'cat';
    } else {
        $tagPrefix = 'tag';
    }
}

if ($groupPrefix !== '' && in_array($groupPrefix, [$categoryPrefix, $tagPrefix], true)) {
    $groupPrefix = 'group';
    if (in_array($groupPrefix, [$categoryPrefix, $tagPrefix], true)) {
        $groupPrefix = 'grp';
    }
}

if ($profilePrefix !== '' && in_array($profilePrefix, [$categoryPrefix, $tagPrefix, $groupPrefix], true)) {
    $profilePrefix = 'user';
    if (in_array($profilePrefix, [$categoryPrefix, $tagPrefix, $groupPrefix], true)) {
        $profilePrefix = 'profile';
    }
}

if ($groupPrefix !== '' && $groupPrefix === $profilePrefix) {
    $groupPrefix = 'group';
    if ($groupPrefix === $profilePrefix || in_array($groupPrefix, [$categoryPrefix, $tagPrefix], true)) {
        $groupPrefix = 'grp';
    }
}

$reservedPrefixes = array_values(array_unique(array_filter([
    trim($panelPath, '/'),
    'boot',
    'mce',
    'theme',
    'login',
    'register',
    'forms',
    $categoryPrefix,
    $tagPrefix,
    $profilePrefix,
    $groupPrefix,
    $feedsEnabled ? $rssFeedRoute : '',
    $feedsEnabled ? $atomFeedRoute : '',
], static fn (string $value): bool => trim($value) !== '')));

$router = new Router();

// Homepage route.
$router->add('GET', '/', static function () use ($controller): void {
    $controller->home();
});

$router->add('GET', '/login', static function () use ($controller): void {
    $controller->login();
});

$router->add('POST', '/login', static function () use ($controller): void {
    $controller->loginSubmit($_POST);
});

$router->add('GET', '/login/2fa', static function () use ($controller): void {
    $controller->loginTwoFactor();
});

$router->add('POST', '/login/2fa', static function () use ($controller): void {
    $controller->loginTwoFactorSubmit($_POST);
});

$router->add('POST', '/login/2fa/select', static function () use ($controller): void {
    $controller->loginTwoFactorSelect($_POST);
});

$router->add('POST', '/login/2fa/webauthn/options', static function () use ($controller): void {
    $controller->loginTwoFactorWebauthnOptions($_POST);
});

$router->add('POST', '/login/2fa/webauthn/verify', static function () use ($controller): void {
    $controller->loginTwoFactorWebauthnVerify($_POST);
});

$router->add('GET', '/register', static function () use ($controller): void {
    $controller->register();
});

$router->add('POST', '/register', static function () use ($controller): void {
    $controller->registerSubmit($_POST);
});

// Extension-agnostic embedded form submit endpoint.
$router->add('POST', '/forms/submit', static function () use ($controller, $input): void {
    $type = $input->slug((string) ($_POST['_rvn_form_type'] ?? ''));
    $slug = $input->slug((string) ($_POST['_rvn_form_slug'] ?? ''));
    if ($type === null || $slug === null) {
        $controller->notFound();
        return;
    }

    $controller->submitEmbeddedForm($type, $slug);
});

// Extension public route registration.
//
// Each enabled `module` extension can optionally provide `private/ext/{name}/lib/routes_public.php`
// that returns a callable with signature:
//   function (Router $router, array $context): void
//
// Context keys:
// - app: container array from bootstrap
// - controller: PublicController instance
// - input: InputSanitizer instance
// - extensionDirectory: enabled extension folder name
$enabledPublicExtensions = ExtensionRegistry::enabledDirectories((string) ($app['root'] ?? dirname(__DIR__)), true);
foreach ($enabledPublicExtensions as $extensionName) {
    $manifest = ExtensionRegistry::readManifest((string) ($app['root'] ?? dirname(__DIR__)), $extensionName);
    if (!is_array($manifest)) {
        continue;
    }

    $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
    $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
    if ($isSystemType || $type !== 'module') {
        continue;
    }

    $routesFile = $app['root'] . '/private/ext/' . $extensionName . '/lib/routes_public.php';
    if (!is_file($routesFile)) {
        continue;
    }

    /** @var mixed $registrar */
    $registrar = require $routesFile;
    if (!is_callable($registrar)) {
        continue;
    }

    $registrar($router, [
        'app' => $app,
        'controller' => $controller,
        'input' => $input,
        'extensionDirectory' => $extensionName,
    ]);
}

if ($feedsEnabled && $rssFeedRoute !== '') {
    $router->add('GET', '/' . $rssFeedRoute, static function () use ($controller): void {
        $controller->rssFeed();
    });

    $router->add('GET', '/' . $rssFeedRoute . '/{channel}', static function (array $params) use ($controller, $input, $reservedPrefixes): void {
        $channel = $input->slug($params['channel'] ?? null);

        if ($channel === null || in_array($channel, $reservedPrefixes, true)) {
            $controller->notFound();
            return;
        }

        $controller->rssFeed($channel);
    });

    if ($categoryPrefix !== '') {
        $router->add('GET', '/' . $rssFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($controller, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $controller->notFound();
                return;
            }

            $controller->rssCategoryFeed($slug);
        });
    }

    if ($tagPrefix !== '') {
        $router->add('GET', '/' . $rssFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($controller, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $controller->notFound();
                return;
            }

            $controller->rssTagFeed($slug);
        });
    }
}

if ($feedsEnabled && $atomFeedRoute !== '') {
    $router->add('GET', '/' . $atomFeedRoute, static function () use ($controller): void {
        $controller->atomFeed();
    });

    $router->add('GET', '/' . $atomFeedRoute . '/{channel}', static function (array $params) use ($controller, $input, $reservedPrefixes): void {
        $channel = $input->slug($params['channel'] ?? null);

        if ($channel === null || in_array($channel, $reservedPrefixes, true)) {
            $controller->notFound();
            return;
        }

        $controller->atomFeed($channel);
    });

    if ($categoryPrefix !== '') {
        $router->add('GET', '/' . $atomFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($controller, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $controller->notFound();
                return;
            }

            $controller->atomCategoryFeed($slug);
        });
    }

    if ($tagPrefix !== '') {
        $router->add('GET', '/' . $atomFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($controller, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $controller->notFound();
                return;
            }

            $controller->atomTagFeed($slug);
        });
    }
}

// Category routes with optional page number path segment.
if ($categoryPrefix !== '') {
    $categoryRouteBase = '/' . $categoryPrefix;
    $router->add('GET', $categoryRouteBase . '/{slug}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $controller->notFound();
            return;
        }

        $controller->category($slug, 1);
    });

    $router->add('GET', $categoryRouteBase . '/{slug}/{page}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);
        $page = $input->int($params['page'] ?? null, 1);

        if ($slug === null || $page === null) {
            $controller->notFound();
            return;
        }

        $controller->category($slug, $page);
    });
}

// Tag routes with optional page number path segment.
if ($tagPrefix !== '') {
    $tagRouteBase = '/' . $tagPrefix;
    $router->add('GET', $tagRouteBase . '/{slug}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $controller->notFound();
            return;
        }

        $controller->tag($slug, 1);
    });

    $router->add('GET', $tagRouteBase . '/{slug}/{page}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);
        $page = $input->int($params['page'] ?? null, 1);

        if ($slug === null || $page === null) {
            $controller->notFound();
            return;
        }

        $controller->tag($slug, $page);
    });
}

// Public profile route when a profile URL prefix is configured.
if ($profilePrefix !== '') {
    $profileRouteBase = '/' . $profilePrefix;
    $router->add('GET', $profileRouteBase . '/{username}', static function (array $params) use ($controller, $input): void {
        $username = $input->text(rawurldecode((string) ($params['username'] ?? '')), 254);

        if ($username === '') {
            $controller->notFound();
            return;
        }

        $controller->profile($username);
    });
}

// Public group route when a group URL prefix is configured.
if ($groupPrefix !== '') {
    $groupRouteBase = '/' . $groupPrefix;
    $router->add('GET', $groupRouteBase . '/{slug}', static function (array $params) use ($controller, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $controller->notFound();
            return;
        }

        $controller->group($slug);
    });
}

// Single-segment route: channel landing first, then root page/redirect fallback.
$router->add('GET', '/{slug}', static function (array $params) use ($controller, $input, $reservedPrefixes): void {
    $slug = $input->slug($params['slug'] ?? null);

    if ($slug === null || in_array($slug, $reservedPrefixes, true)) {
        $controller->notFound();
        return;
    }

    $controller->channel($slug);
});

// Channel + page route for pages assigned to channels.
$router->add('GET', '/{channel}/{slug}', static function (array $params) use ($controller, $input, $reservedPrefixes): void {
    $channel = $input->slug($params['channel'] ?? null);
    $slugRaw = strtolower(trim((string) ($params['slug'] ?? '')));

    if (
        $channel === null
        || $slugRaw === ''
        || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slugRaw) !== 1
        || in_array($channel, $reservedPrefixes, true)
    ) {
        $controller->notFound();
        return;
    }

    $controller->page($slugRaw, $channel);
});

$method = $requestMethod;
$path = request_path();

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

// Keep public auth helper routes reachable even when site mode is private/disabled.
$bypassAvailabilityPaths = ['/login', '/register'];
$bypassAvailability = in_array($path, $bypassAvailabilityPaths, true);

if (!$bypassAvailability && !$controller->enforceSiteAvailability()) {
    exit;
}

$dispatchResult = $router->dispatch(new RouteRequest($method, $path));
if (!$dispatchResult->isHandled()) {
    $controller->notFound();
}
