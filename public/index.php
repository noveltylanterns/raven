<?php

/**
 * RAVEN CMS
 * ~/public/index.php
 * Public web front controller for site routing.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\PublicController;
use Raven\Core\Debug\DebugToolbarRenderer;
use Raven\Core\Debug\RequestProfiler;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Core\Routing\Router;

use function Raven\Core\Support\request_path;

/**
 * Public front controller for https://{domain}/
 */
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = $requestPath === '' ? '/' : $requestPath;

/**
 * Installer handoff:
 * - If runtime config is missing and install lock is absent, redirect to installer.
 * - If request explicitly targets installer, run installer script directly.
 */
$configPath = dirname(__DIR__) . '/private/config.php';
$installLockPath = dirname(__DIR__) . '/private/tmp/install.lock';

if (!is_file($configPath)) {
    if (!is_file($installLockPath)) {
        header('Location: /install.php', true, 302);
        exit;
    }

    http_response_code(500);
    echo 'Raven configuration file is missing.';
    exit;
}

if ($requestPath === '/install.php') {
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

$app = require dirname(__DIR__) . '/private/bootstrap.php';

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
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
    $requestMethod === 'GET'
    && isset($app['auth'])
    && $app['auth']->canManageConfiguration()
) {
    if ($debugToolbarSettings['show_on_public']) {
        RequestProfiler::start((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 'public');
        RequestProfiler::enable();
        $debugToolbarEnabled = true;
    }
}

if ($debugToolbarEnabled) {
    ob_start(static function (string $body) use ($app, $debugToolbarSettings, $requestPath, $requestMethod): string {
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
    $app['groups'],
    $app['page_images'],
    $app['pages'],
    $app['redirects'],
    $app['taxonomy'],
    $app['users'],
    $app['input'],
    $app['csrf'],
    is_array($app['extension_services'] ?? null) ? (array) $app['extension_services'] : []
);

$input = $app['input'];

$readOptionalRoutePrefix = static function (string $rawValue, string $fallback) use ($input): string {
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return '';
    }

    return $input->slug($rawValue) ?? $fallback;
};

$panelPath = (string) $app['config']->get('panel.path', 'panel');
$categoryPrefix = $readOptionalRoutePrefix((string) $app['config']->get('categories.prefix', 'cat'), 'cat');
$tagPrefix = $readOptionalRoutePrefix((string) $app['config']->get('tags.prefix', 'tag'), 'tag');
$profilePrefix = $readOptionalRoutePrefix((string) $app['config']->get('session.profile_prefix', 'user'), 'user');
$groupPrefix = $readOptionalRoutePrefix((string) $app['config']->get('session.group_prefix', 'group'), 'group');

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
        $groupPrefix = 'groups';
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
        $groupPrefix = 'groups';
    }
}

$reservedPrefixes = array_values(array_unique(array_filter([
    trim($panelPath, '/'),
    'boot',
    'mce',
    'theme',
    $categoryPrefix,
    $tagPrefix,
    $profilePrefix,
    $groupPrefix,
], static fn (string $value): bool => trim($value) !== '')));

$router = new Router();

// Homepage route.
$router->add('GET', '/', static function () use ($controller): void {
    $controller->home();
});

// Extension public route registration.
//
// Each enabled extension can optionally provide `private/ext/{name}/public_routes.php`
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
    if (is_array($manifest)) {
        $type = strtolower(trim((string) ($manifest['type'] ?? 'basic')));
        if ($type === 'helper') {
            continue;
        }
    }

    $routesFile = $app['root'] . '/private/ext/' . $extensionName . '/public_routes.php';
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
        $username = $input->username($params['username'] ?? null);

        if ($username === null) {
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
    $slug = $input->slug($params['slug'] ?? null);

    if ($channel === null || $slug === null || in_array($channel, $reservedPrefixes, true)) {
        $controller->notFound();
        return;
    }

    $controller->page($slug, $channel);
});

$method = $requestMethod;
$path = request_path();

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

if (!$controller->enforceSiteAvailability()) {
    exit;
}

if (!$router->dispatch($method, $path)) {
    $controller->notFound();
}
