<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/PublicController.php
 * Public web entry orchestration and dispatch.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Debug\OutputProfilerConfigResolver;
use Raven\Core\Debug\OutputProfilerResponseHook;
use Raven\Core\Routing\Request;
use Raven\Core\Routing\Router;
use Raven\Core\Routing\Public\AuthRouter;
use Raven\Core\Routing\Public\CategoryRouter;
use Raven\Core\Routing\Public\ChannelRouter;
use Raven\Core\Routing\Public\ContentRouter;
use Raven\Core\Routing\Public\ExtensionRouter;
use Raven\Core\Routing\Public\FeedRouter;
use Raven\Core\Routing\Public\FormRouter;
use Raven\Core\Routing\Public\GroupRouter;
use Raven\Core\Routing\Public\ProfileRouter;
use Raven\Core\Routing\Public\RouteConfig;
use Raven\Core\Routing\Public\PublicRuntimeBuilder;
use Raven\Core\Routing\Public\TagRouter;
use Raven\Lib\Scheduler\Cron;
use RuntimeException;

/**
 * Owns public-entry orchestration.
 *
 * Keep this class limited to public-global entry work that runs before or
 * around route dispatch on every public request. Route-family-specific work
 * should keep moving into dedicated public routing registrars and controllers.
 */
final class PublicController
{
    /**
     * Handles the current public request from config/install checks through dispatch.
     *
     * @return void
     */
    public static function handle(): void
    {
        $root = self::rootPath();
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
        $rvn = PublicRuntimeBuilder::build($rvn);

        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $profilerSettings = OutputProfilerConfigResolver::fromConfig($rvn['config']);
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
            if (!isset($rvn['auth']) || $isPublicAuthHelperPath($requestPath)) {
                return false;
            }

            $userId = $rvn['auth']->userId();
            if ($userId === null || !$rvn['auth']->canManageConfiguration($userId)) {
                return false;
            }

            return $rvn['auth']->isTwoFactorVerifiedForUser($userId);
        };

        OutputProfilerResponseHook::arm(
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

        /** @var callable(): object $publicPageController */
        $publicPageController = is_callable($rvn['public_page_controller'] ?? null)
            ? $rvn['public_page_controller']
            : static function (): object {
                throw new RuntimeException('Public page controller factory is unavailable.');
            };

        /** @var callable(): object $publicAuthController */
        $publicAuthController = is_callable($rvn['public_auth_controller'] ?? null)
            ? $rvn['public_auth_controller']
            : static function (): object {
                throw new RuntimeException('Public auth controller factory is unavailable.');
            };

        /** @var callable(): object $publicUserController */
        $publicUserController = is_callable($rvn['public_user_controller'] ?? null)
            ? $rvn['public_user_controller']
            : static function (): object {
                throw new RuntimeException('Public user controller factory is unavailable.');
            };

        /** @var callable(): object $publicCategoryController */
        $publicCategoryController = is_callable($rvn['public_category_controller'] ?? null)
            ? $rvn['public_category_controller']
            : static function (): object {
                throw new RuntimeException('Public category controller factory is unavailable.');
            };

        /** @var callable(): object $publicChannelController */
        $publicChannelController = is_callable($rvn['public_channel_controller'] ?? null)
            ? $rvn['public_channel_controller']
            : static function (): object {
                throw new RuntimeException('Public channel controller factory is unavailable.');
            };

        /** @var callable(): object $publicGroupController */
        $publicGroupController = is_callable($rvn['public_group_controller'] ?? null)
            ? $rvn['public_group_controller']
            : static function (): object {
                throw new RuntimeException('Public group controller factory is unavailable.');
            };

        /** @var callable(): object $publicFeedController */
        $publicFeedController = is_callable($rvn['public_feed_controller'] ?? null)
            ? $rvn['public_feed_controller']
            : static function (): object {
                throw new RuntimeException('Public feed controller factory is unavailable.');
            };

        /** @var callable(): object $publicTagController */
        $publicTagController = is_callable($rvn['public_tag_controller'] ?? null)
            ? $rvn['public_tag_controller']
            : static function (): object {
                throw new RuntimeException('Public tag controller factory is unavailable.');
            };

        /** @var callable(): object $publicRequestContext */
        $publicRequestContext = is_callable($rvn['public_request_context'] ?? null)
            ? $rvn['public_request_context']
            : static function (): object {
                throw new RuntimeException('Public request context factory is unavailable.');
            };

        $input = $rvn['input'];
        $routeConfig = RouteConfig::build($rvn['config'], $input);
        $router = new Router();

        AuthRouter::register($router, $publicAuthController);
        FormRouter::register($router, $publicPageController, $publicRequestContext, $input);
        ExtensionRouter::register($router, $rvn, $publicRequestContext, $input);
        CategoryRouter::register($router, $publicCategoryController, $publicRequestContext, $input, $routeConfig);
        ChannelRouter::register($router, $publicChannelController, $publicRequestContext, $input, $routeConfig);
        FeedRouter::register($router, $publicFeedController, $publicRequestContext, $input, $routeConfig);
        ProfileRouter::register($router, $publicUserController, $publicRequestContext, $input, $routeConfig);
        GroupRouter::register($router, $publicGroupController, $publicRequestContext, $input, $routeConfig);
        TagRouter::register($router, $publicTagController, $publicRequestContext, $input, $routeConfig);
        ContentRouter::register($router, $publicPageController, $publicRequestContext, $input, $routeConfig);

        $method = $requestMethod;
        $path = \Raven\Lib\Transport\Request::path();

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

        $dispatchResult = $router->dispatch(new Request($method, $path));
        if (!$dispatchResult->isHandled()) {
            $publicRequestContext()->notFound();
        }

        Cron::runIfDue(
            $rvn,
            $root,
            $rvn['config']->get('site.scheduler', 'always') === 'always'
        );
    }

    /**
     * Returns the project root for this checkout.
     *
     * @return string Absolute Raven project root path.
     */
    private static function rootPath(): string
    {
        return dirname(__DIR__, 4);
    }
}
