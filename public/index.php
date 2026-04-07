<?php

/**
 * RAVEN CMS
 * ~/public/index.php
 * Public web front controller for site routing.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Diagnostics\DebugToolbarRenderer;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarConfigResolver;
use Raven\Lib\Diagnostics\RequestProfiler;
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

$rvn = require dirname(__DIR__) . '/private/raven.php';
$publicBootstrap = require __DIR__ . '/bootstrap.php';
$rvn = $publicBootstrap($rvn);

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$debugToolbarSettings = DebugToolbarConfigResolver::fromConfig($rvn['config']);
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
$canRenderPublicDebugToolbar = static function () use ($rvn, $isPublicAuthHelperPath, $requestPath): bool {
    if (!isset($rvn['auth']) || $isPublicAuthHelperPath($requestPath)) {
        return false;
    }

    $userId = $rvn['auth']->userId();
    if ($userId === null || !$rvn['auth']->canManageConfiguration($userId)) {
        return false;
    }

    return $rvn['auth']->isTwoFactorVerifiedForUser($userId);
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
    ob_start(static function (string $body) use ($rvn, $debugToolbarSettings, $requestPath, $requestMethod, $canRenderPublicDebugToolbar): string {
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

/** @var callable(): object $publicController */
$publicController = is_callable($rvn['public_controller'] ?? null)
    ? $rvn['public_controller']
    : static function (): object {
        throw new RuntimeException('Public controller factory is unavailable.');
    };

/** @var callable(): object $publicAuthController */
$publicAuthController = is_callable($rvn['public_auth_controller'] ?? null)
    ? $rvn['public_auth_controller']
    : static function (): object {
        throw new RuntimeException('Public auth controller factory is unavailable.');
    };

/** @var callable(): object $publicProfileController */
$publicProfileController = is_callable($rvn['public_profile_controller'] ?? null)
    ? $rvn['public_profile_controller']
    : static function (): object {
        throw new RuntimeException('Public profile controller factory is unavailable.');
    };

/** @var callable(): object $publicFormController */
$publicFormController = is_callable($rvn['public_form_controller'] ?? null)
    ? $rvn['public_form_controller']
    : static function (): object {
        throw new RuntimeException('Public form controller factory is unavailable.');
    };

/** @var callable(): object $publicFeedController */
$publicFeedController = is_callable($rvn['public_feed_controller'] ?? null)
    ? $rvn['public_feed_controller']
    : static function (): object {
        throw new RuntimeException('Public feed controller factory is unavailable.');
    };

$input = $rvn['input'];
$routeConfig = new RouteConfigService($rvn['config'], $input);

$panelPath = (string) $rvn['config']->get('panel.path', 'panel');
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
$router->add('GET', '/', static function () use ($publicController): void {
    $publicController()->home();
});

$router->add('GET', '/login', static function () use ($publicAuthController): void {
    $publicAuthController()->login();
});

$router->add('POST', '/login', static function () use ($publicAuthController): void {
    $publicAuthController()->loginSubmit($_POST);
});

$router->add('GET', '/login/2fa', static function () use ($publicAuthController): void {
    $publicAuthController()->loginTwoFactor();
});

$router->add('POST', '/login/2fa', static function () use ($publicAuthController): void {
    $publicAuthController()->loginTwoFactorSubmit($_POST);
});

$router->add('POST', '/login/2fa/select', static function () use ($publicAuthController): void {
    $publicAuthController()->loginTwoFactorSelect($_POST);
});

$router->add('POST', '/login/2fa/webauthn/options', static function () use ($publicAuthController): void {
    $publicAuthController()->loginTwoFactorWebauthnOptions($_POST);
});

$router->add('POST', '/login/2fa/webauthn/verify', static function () use ($publicAuthController): void {
    $publicAuthController()->loginTwoFactorWebauthnVerify($_POST);
});

$router->add('GET', '/register', static function () use ($publicAuthController): void {
    $publicAuthController()->register();
});

$router->add('POST', '/register', static function () use ($publicAuthController): void {
    $publicAuthController()->registerSubmit($_POST);
});

// Extension-agnostic embedded form submit endpoint.
$router->add('POST', '/forms/submit', static function () use ($publicFormController, $publicController, $input): void {
    $type = $input->slug((string) ($_POST['_rvn_form_type'] ?? ''));
    $slug = $input->slug((string) ($_POST['_rvn_form_slug'] ?? ''));
    if ($type === null || $slug === null) {
        $publicController()->notFound();
        return;
    }

    $publicFormController()->submitEmbeddedForm($type, $slug);
});

// Extension public route registration.
//
// Each enabled `module` extension can optionally provide `private/ext/{name}/lib/routes_public.php`
// that returns a callable with signature:
//   function (Router $router, array $context): void
//
// Context keys:
// - rvn: container array from bootstrap
// - notFound: callable(): void Raven public 404 responder
// - extensionServices: callable(?string $extensionDirectory = null): array lazy extension-services resolver
// - input: InputSanitizer instance
// - extensionDirectory: enabled extension folder name
/** @var array<string, array<string, mixed>> $enabledPublicExtensionManifests */
$enabledPublicExtensionManifests = is_array($rvn['enabled_extension_manifests'] ?? null)
    ? (array) $rvn['enabled_extension_manifests']
    : [];
foreach ($enabledPublicExtensionManifests as $extensionName => $manifest) {
    $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
    $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
    if ($isSystemType || $type !== 'module') {
        continue;
    }

    $routesFile = $rvn['root'] . '/private/ext/' . $extensionName . '/lib/routes_public.php';
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

    // Pre-resolve the extension tpl root so the renderPublicExtension closure
    // does not need to reconstruct it on every invocation.
    $extTplRoot = rtrim((string) ($rvn['root'] ?? ''), '/') . '/private/ext/' . $extensionName . '/tpl';

    $registrar($router, [
        'rvn' => $extensionRouteRvn,
        'input' => $input,
        'extensionDirectory' => $extensionName,
        'extensionServices' => is_callable($rvn['public_extension_services'] ?? null)
            ? $rvn['public_extension_services']
            : static fn (): array => [],
        'notFound' => static function () use ($publicController): void {
            $publicController()->notFound();
        },
        // First-class render helper: renders extension templates through the site theme pipeline.
        // Signature: renderPublicExtension(string $template, array $data = [], string|null $layout = 'wrapper'): void
        // Templates resolve from: active theme > extension tpl/ > core tpl/ fallback.
        'renderPublicExtension' => static function (string $template, array $data = [], ?string $layout = 'wrapper') use ($publicController, $extTplRoot): void {
            $publicController()->renderPublicExtensionTemplate($template, $data, $layout, $extTplRoot);
        },
    ]);
}

if ($feedsEnabled && $rssFeedRoute !== '') {
    $router->add('GET', '/' . $rssFeedRoute, static function () use ($publicFeedController): void {
        $publicFeedController()->rssFeed();
    });

    $router->add('GET', '/' . $rssFeedRoute . '/{channel}', static function (array $params) use ($publicFeedController, $publicController, $input, $reservedPrefixes): void {
        $channel = $input->slug($params['channel'] ?? null);

        if ($channel === null || in_array($channel, $reservedPrefixes, true)) {
            $publicController()->notFound();
            return;
        }

        $publicFeedController()->rssFeed($channel);
    });

    if ($categoryPrefix !== '') {
        $router->add('GET', '/' . $rssFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicController()->notFound();
                return;
            }

            $publicFeedController()->rssCategoryFeed($slug);
        });
    }

    if ($tagPrefix !== '') {
        $router->add('GET', '/' . $rssFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicController()->notFound();
                return;
            }

            $publicFeedController()->rssTagFeed($slug);
        });
    }
}

if ($feedsEnabled && $atomFeedRoute !== '') {
    $router->add('GET', '/' . $atomFeedRoute, static function () use ($publicFeedController): void {
        $publicFeedController()->atomFeed();
    });

    $router->add('GET', '/' . $atomFeedRoute . '/{channel}', static function (array $params) use ($publicFeedController, $publicController, $input, $reservedPrefixes): void {
        $channel = $input->slug($params['channel'] ?? null);

        if ($channel === null || in_array($channel, $reservedPrefixes, true)) {
            $publicController()->notFound();
            return;
        }

        $publicFeedController()->atomFeed($channel);
    });

    if ($categoryPrefix !== '') {
        $router->add('GET', '/' . $atomFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicController()->notFound();
                return;
            }

            $publicFeedController()->atomCategoryFeed($slug);
        });
    }

    if ($tagPrefix !== '') {
        $router->add('GET', '/' . $atomFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicController()->notFound();
                return;
            }

            $publicFeedController()->atomTagFeed($slug);
        });
    }
}

// Category routes with optional page number path segment.
if ($categoryPrefix !== '') {
    $categoryRouteBase = '/' . $categoryPrefix;
    $router->add('GET', $categoryRouteBase . '/{slug}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $publicController()->notFound();
            return;
        }

        $publicFeedController()->category($slug, 1);
    });

    $router->add('GET', $categoryRouteBase . '/{slug}/{page}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
        $slug = $input->slug($params['slug'] ?? null);
        $page = $input->int($params['page'] ?? null, 1);

        if ($slug === null || $page === null) {
            $publicController()->notFound();
            return;
        }

        $publicFeedController()->category($slug, $page);
    });
}

// Tag routes with optional page number path segment.
if ($tagPrefix !== '') {
    $tagRouteBase = '/' . $tagPrefix;
    $router->add('GET', $tagRouteBase . '/{slug}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $publicController()->notFound();
            return;
        }

        $publicFeedController()->tag($slug, 1);
    });

    $router->add('GET', $tagRouteBase . '/{slug}/{page}', static function (array $params) use ($publicFeedController, $publicController, $input): void {
        $slug = $input->slug($params['slug'] ?? null);
        $page = $input->int($params['page'] ?? null, 1);

        if ($slug === null || $page === null) {
            $publicController()->notFound();
            return;
        }

        $publicFeedController()->tag($slug, $page);
    });
}

// Public profile route when a profile URL prefix is configured.
if ($profilePrefix !== '') {
    $profileRouteBase = '/' . $profilePrefix;
    $router->add('GET', $profileRouteBase . '/{username}', static function (array $params) use ($publicProfileController, $publicController, $input): void {
        $username = $input->text(rawurldecode((string) ($params['username'] ?? '')), 254);

        if ($username === '') {
            $publicController()->notFound();
            return;
        }

        $publicProfileController()->profile($username);
    });
}

// Public group route when a group URL prefix is configured.
if ($groupPrefix !== '') {
    $groupRouteBase = '/' . $groupPrefix;
    $router->add('GET', $groupRouteBase . '/{slug}', static function (array $params) use ($publicProfileController, $publicController, $input): void {
        $slug = $input->slug($params['slug'] ?? null);

        if ($slug === null) {
            $publicController()->notFound();
            return;
        }

        $publicProfileController()->group($slug);
    });
}

// Single-segment route: channel landing first, then root page/redirect fallback.
$router->add('GET', '/{slug}', static function (array $params) use ($publicController, $input, $reservedPrefixes): void {
    $slug = $input->slug($params['slug'] ?? null);

    if ($slug === null || in_array($slug, $reservedPrefixes, true)) {
        $publicController()->notFound();
        return;
    }

    $publicController()->channel($slug);
});

// Channel + page route for pages assigned to channels.
$router->add('GET', '/{channel}/{slug}', static function (array $params) use ($publicController, $input, $reservedPrefixes): void {
    $channel = $input->slug($params['channel'] ?? null);
    $slugRaw = strtolower(trim((string) ($params['slug'] ?? '')));

    if (
        $channel === null
        || $slugRaw === ''
        || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slugRaw) !== 1
        || in_array($channel, $reservedPrefixes, true)
    ) {
        $publicController()->notFound();
        return;
    }

    $publicController()->page($slugRaw, $channel);
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

if (!$bypassAvailability && !$publicController()->enforceSiteAvailability()) {
    exit;
}

$dispatchResult = $router->dispatch(new RouteRequest($method, $path));
if (!$dispatchResult->isHandled()) {
    $publicController()->notFound();
}

// Fallback scheduler: runs all due jobs (core + extensions) non-blocking after the response
// is delivered, so visitors never wait on scheduler overhead. Throttled to one attempt per
// 60 s via a .tmp timestamp file shared across both public and panel entrypoints.
// Operators who prefer to manage their own server crontab can disable this via site.scheduler.
if ($rvn['config']->get('site.scheduler', 'always') === 'always') {
    $schedulerStampFile = dirname(__DIR__) . '/private/dat/scheduler_last_run';
    $lastRun = is_file($schedulerStampFile) ? (int) @file_get_contents($schedulerStampFile) : 0;
    if (time() - $lastRun >= 60) {
        @file_put_contents($schedulerStampFile, (string) time());
        // On PHP-FPM, finish_request flushes the response before running jobs.
        // On other SAPIs this runs synchronously after output — still correct, just not async.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $rvn['scheduler']->runDue(['root' => dirname(__DIR__), 'rvn' => $rvn]);
    }
}
