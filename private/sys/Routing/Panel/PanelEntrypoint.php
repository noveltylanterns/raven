<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelEntrypoint.php
 * Panel web entry orchestration and dispatch.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Auth\PanelAccess;
use Raven\Lib\Config\Config;
use Raven\Lib\Diagnostics\RequestProfiler;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarConfigResolver;
use Raven\Lib\Diagnostics\Toolbar\DebugToolbarRenderer;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Routing\RouteRequest;
use Raven\Lib\Routing\Router;
use RuntimeException;

use function Raven\Support\request_path;
use function Raven\Support\redirect;

/**
 * Owns panel-entry orchestration.
 *
 * Keep this class limited to panel-global entry work that runs before or
 * around route dispatch on every panel request. Route-family-specific work
 * should keep moving into dedicated panel routing registrars and controllers.
 */
final class PanelEntrypoint
{
    /**
     * Handles the current panel request from path normalization through dispatch.
     */
    public static function handle(): void
    {
        $root = self::rootPath();
        /** @var array<string, mixed> $rvn */
        $rvn = require $root . '/private/raven.php';
        $rvn = PanelRuntimeBuilder::build($rvn);

        /** @var callable(): object $authController */
        $authController = is_callable($rvn['auth_controller'] ?? null)
            ? $rvn['auth_controller']
            : static function (): object {
                throw new RuntimeException('Panel auth controller factory is unavailable.');
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

        /** @var callable(): object $panelSystemController */
        $panelSystemController = is_callable($rvn['panel_system_controller'] ?? null)
            ? $rvn['panel_system_controller']
            : static function (): object {
                throw new RuntimeException('Panel system controller factory is unavailable.');
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

        $categoryEnabled = Config::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = Config::bool($rvn['config']->get('tag.enabled', true), true);
        $_SESSION['_raven_category_enabled'] = $categoryEnabled;
        $_SESSION['_raven_tag_enabled'] = $tagEnabled;

        // Serve theme assets before panel route dispatch when front-controller rewrite is enabled.
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (PanelThemeAssetResponder::serveIfMatched($rvn, $internalPath, $requestMethod)) {
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
            $extensionPermissionCatalog = $panelSystemController()->extensionPanelPermissionMapForDirectories(array_keys($enabledExtensionManifests));

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
        PanelAuthRouteRegistrar::register($router, $authController);
        PanelDashboardRouteRegistrar::register($router, $panelDashboardController);
        PanelContentRouteRegistrar::register($router, $panelContentController, $rvn['input']);
        PanelTaxonomyRouteRegistrar::register($router, $panelTaxonomyController, $rvn['input'], $categoryEnabled, $tagEnabled);
        PanelRedirectRouteRegistrar::register($router, $panelRedirectController, $rvn['input']);
        PanelUserRouteRegistrar::register($router, $panelUserController, $rvn['input']);
        PanelGroupRouteRegistrar::register($router, $panelGroupController, $rvn['input']);
        PanelPreferencesRouteRegistrar::register($router, $panelPreferencesController);
        PanelSystemRouteRegistrar::register($router, $panelSystemController);

        PanelExtensionRouteRegistrar::register(
            $router,
            $rvn,
            $enabledExtensions,
            $enabledExtensionManifests,
            $extensionPermissionCatalog,
            $internalPath,
            $panelSystemController
        );

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
            ob_start(static function (string $body) use ($debugToolbarSettings, $internalPath, $method, $canRenderPanelDebugToolbar): string {
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

        $dispatchResult = $router->dispatch(new RouteRequest($method, $internalPath));
        if (!$dispatchResult->isHandled()) {
            if ($shouldInitializeFullPanelRuntime) {
                $panelSystemController()->renderPublicNotFound();
            } else {
                http_response_code(404);
                echo 'Not Found';
            }
        }

        if (in_array($rvn['config']->get('site.scheduler', 'always'), ['always', 'panel'], true)) {
            $schedulerStampFile = $root . '/private/dat/scheduler_last_run';
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
