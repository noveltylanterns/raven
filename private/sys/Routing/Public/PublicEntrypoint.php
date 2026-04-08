<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicEntrypoint.php
 * Public web entry orchestration and dispatch.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Lib\Diagnostics\RequestProfiler;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarConfigResolver;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarRenderer;
use Raven\Lib\Routing\Router;
use Raven\Lib\Routing\RouteRequest;
use RuntimeException;

use function Raven\Lib\Support\request_path;

/**
 * Owns public-entry orchestration.
 *
 * Keep this class limited to public-global entry work that runs before or
 * around route dispatch on every public request. Route-family-specific work
 * should keep moving into dedicated public routing registrars and controllers.
 */
final class PublicEntrypoint
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

        /** @var array<string, mixed> $rvn */
        $rvn = require $root . '/private/raven.php';
        $rvn = PublicRuntimeBuilder::build($rvn);

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
            ob_start(static function (string $body) use ($debugToolbarSettings, $requestPath, $requestMethod, $canRenderPublicDebugToolbar): string {
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

        /** @var callable(): object $publicContentController */
        $publicContentController = is_callable($rvn['public_content_controller'] ?? null)
            ? $rvn['public_content_controller']
            : static function (): object {
                throw new RuntimeException('Public content controller factory is unavailable.');
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

        /** @var callable(): object $publicRequestContext */
        $publicRequestContext = is_callable($rvn['public_request_context'] ?? null)
            ? $rvn['public_request_context']
            : static function (): object {
                throw new RuntimeException('Public request context factory is unavailable.');
            };

        $input = $rvn['input'];
        $routeConfig = PublicRouteConfig::build($rvn['config'], $input);
        $router = new Router();

        PublicAuthRouteRegistrar::register($router, $publicAuthController);
        PublicFormRouteRegistrar::register($router, $publicFormController, $publicRequestContext, $input);
        PublicExtensionRouteRegistrar::register($router, $rvn, $publicRequestContext, $input);
        PublicFeedRouteRegistrar::register($router, $publicFeedController, $publicRequestContext, $input, $routeConfig);
        PublicProfileRouteRegistrar::register($router, $publicProfileController, $publicRequestContext, $input, $routeConfig);
        PublicContentRouteRegistrar::register($router, $publicContentController, $publicRequestContext, $input, $routeConfig);

        $method = $requestMethod;
        $path = request_path();

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

        if ($rvn['config']->get('site.scheduler', 'always') === 'always') {
            $schedulerStampFile = $root . '/private/dat/scheduler_last_run';
            $lastRun = is_file($schedulerStampFile) ? (int) @file_get_contents($schedulerStampFile) : 0;
            if (time() - $lastRun >= 60) {
                @file_put_contents($schedulerStampFile, (string) time());
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $rvn['scheduler']->runDue(['root' => $root, 'rvn' => $rvn]);
            }
        }
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
