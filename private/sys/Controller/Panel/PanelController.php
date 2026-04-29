<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PanelController.php
 * Panel web entry orchestration and dispatch.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Debug\OutputProfilerConfigResolver;
use Raven\Core\Debug\OutputProfilerResponseHook;
use Raven\Core\Routing\Request;
use Raven\Core\Routing\Router;
use Raven\Core\Routing\Panel\AuthRouter;
use Raven\Core\Routing\Panel\CategoryRouter;
use Raven\Core\Routing\Panel\ChannelRouter;
use Raven\Core\Routing\Panel\ConfigRouter;
use Raven\Core\Routing\Panel\ContentRouter;
use Raven\Core\Routing\Panel\DashboardRouter;
use Raven\Core\Routing\Panel\ExtensionRouter;
use Raven\Core\Routing\Panel\GroupRouter;
use Raven\Core\Routing\Panel\LogRouter;
use Raven\Core\Routing\Panel\PreferencesRouter;
use Raven\Core\Routing\Panel\RedirectRouter;
use Raven\Core\Routing\Panel\RoutingRouter;
use Raven\Core\Routing\Panel\PanelRuntimeBuilder;
use Raven\Core\Routing\Panel\SystemRouter;
use Raven\Core\Routing\Panel\TagRouter;
use Raven\Core\Routing\Panel\ThemeAssetResponder;
use Raven\Core\Routing\Panel\UpdateRouter;
use Raven\Core\Routing\Panel\UserRouter;
use Raven\Lib\Auth\Panel\PanelAccess;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Parser\PanelParser;
use Raven\Lib\Scheduler\Cron;
use Raven\Lib\View\Error as ViewError;
use RuntimeException;


/**
 * Owns panel-entry orchestration.
 *
 * Keep this class limited to panel-global entry work that runs before or
 * around route dispatch on every panel request. Route-family-specific work
 * should keep moving into dedicated panel routing registrars and controllers.
 */
final class PanelController
{
    /**
     * Handles the current panel request from path normalization through dispatch.
     */
    public static function handle(): void
    {
        $root = self::rootPath();
        require_once $root . '/private/Raven.php';
        /** @var array<string, mixed> $rvn */
        $rvn = \Raven\Raven::boot();
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

        /** @var callable(): object $panelChannelListController */
        $panelChannelListController = is_callable($rvn['panel_channel_list_controller'] ?? null)
            ? $rvn['panel_channel_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel channel list controller factory is unavailable.');
            };

        /** @var callable(): object $panelChannelEditController */
        $panelChannelEditController = is_callable($rvn['panel_channel_edit_controller'] ?? null)
            ? $rvn['panel_channel_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel channel edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelCategoryListController */
        $panelCategoryListController = is_callable($rvn['panel_category_list_controller'] ?? null)
            ? $rvn['panel_category_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel category list controller factory is unavailable.');
            };

        /** @var callable(): object $panelCategoryEditController */
        $panelCategoryEditController = is_callable($rvn['panel_category_edit_controller'] ?? null)
            ? $rvn['panel_category_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel category edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelTagListController */
        $panelTagListController = is_callable($rvn['panel_tag_list_controller'] ?? null)
            ? $rvn['panel_tag_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel tag list controller factory is unavailable.');
            };

        /** @var callable(): object $panelTagEditController */
        $panelTagEditController = is_callable($rvn['panel_tag_edit_controller'] ?? null)
            ? $rvn['panel_tag_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel tag edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelRedirectListController */
        $panelRedirectListController = is_callable($rvn['panel_redirect_list_controller'] ?? null)
            ? $rvn['panel_redirect_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel redirect list controller factory is unavailable.');
            };

        /** @var callable(): object $panelRedirectEditController */
        $panelRedirectEditController = is_callable($rvn['panel_redirect_edit_controller'] ?? null)
            ? $rvn['panel_redirect_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel redirect edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelUserListController */
        $panelUserListController = is_callable($rvn['panel_user_list_controller'] ?? null)
            ? $rvn['panel_user_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel user list controller factory is unavailable.');
            };

        /** @var callable(): object $panelUserEditController */
        $panelUserEditController = is_callable($rvn['panel_user_edit_controller'] ?? null)
            ? $rvn['panel_user_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel user edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelGroupListController */
        $panelGroupListController = is_callable($rvn['panel_group_list_controller'] ?? null)
            ? $rvn['panel_group_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel group list controller factory is unavailable.');
            };

        /** @var callable(): object $panelGroupEditController */
        $panelGroupEditController = is_callable($rvn['panel_group_edit_controller'] ?? null)
            ? $rvn['panel_group_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel group edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelPageListController */
        $panelPageListController = is_callable($rvn['panel_page_list_controller'] ?? null)
            ? $rvn['panel_page_list_controller']
            : static function (): object {
                throw new RuntimeException('Panel page list controller factory is unavailable.');
            };

        /** @var callable(): object $panelPageEditController */
        $panelPageEditController = is_callable($rvn['panel_page_edit_controller'] ?? null)
            ? $rvn['panel_page_edit_controller']
            : static function (): object {
                throw new RuntimeException('Panel page edit controller factory is unavailable.');
            };

        /** @var callable(): object $panelPreferencesController */
        $panelPreferencesController = is_callable($rvn['panel_preferences_controller'] ?? null)
            ? $rvn['panel_preferences_controller']
            : static function (): object {
                throw new RuntimeException('Panel preferences controller factory is unavailable.');
            };

        /** @var callable(): object $panelConfigController */
        $panelConfigController = is_callable($rvn['panel_config_controller'] ?? null)
            ? $rvn['panel_config_controller']
            : static function (): object {
                throw new RuntimeException('Panel config controller factory is unavailable.');
            };

        /** @var callable(): object $panelLogsController */
        $panelLogsController = is_callable($rvn['panel_logs_controller'] ?? null)
            ? $rvn['panel_logs_controller']
            : static function (): object {
                throw new RuntimeException('Panel logs controller factory is unavailable.');
            };

        /** @var callable(): object $panelRoutingController */
        $panelRoutingController = is_callable($rvn['panel_routing_controller'] ?? null)
            ? $rvn['panel_routing_controller']
            : static function (): object {
                throw new RuntimeException('Panel routing controller factory is unavailable.');
            };

        /** @var callable(): object $panelUpdateController */
        $panelUpdateController = is_callable($rvn['panel_update_controller'] ?? null)
            ? $rvn['panel_update_controller']
            : static function (): object {
                throw new RuntimeException('Panel update controller factory is unavailable.');
            };

        /** @var callable(): object $panelSystemController */
        $panelSystemController = is_callable($rvn['panel_system_controller'] ?? null)
            ? $rvn['panel_system_controller']
            : static function (): object {
                throw new RuntimeException('Panel system controller factory is unavailable.');
            };

        /** @var callable(array<int, string>=): array<string, array<string, mixed>> $panelPermissionMapProvider */
        $panelPermissionMapProvider = is_callable($rvn['panel_permission_map_provider'] ?? null)
            ? $rvn['panel_permission_map_provider']
            : static function (array $directoryFilter = []): array {
                return [];
            };

        /** @var callable(): object $panelRequestContext */
        $panelRequestContext = is_callable($rvn['panel_request_context'] ?? null)
            ? $rvn['panel_request_context']
            : static function (): object {
                throw new RuntimeException('Panel request context factory is unavailable.');
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
        $requestedPath = \Raven\Lib\Transport\Request::path();
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
        if (ThemeAssetResponder::serveIfMatched($rvn, $internalPath, $requestMethod)) {
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
            // Resolve extension permission levels from the shared runtime provider so
            // panel-entry orchestration does not call the system route controller directly.
            $extensionPermissionCatalog = $panelPermissionMapProvider(array_keys($enabledExtensionManifests));

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

                $extensionRoot = $rvn['root'] . '/private/ext/' . $directoryName;
                $panelRoutesFile = Resolver::providerPath($extensionRoot, 'routes_panel.php');
                if ($panelRoutesFile === null) {
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
                foreach ($rvn['panel_domain_content']()['channel_read']->listOptions() as $channelOption) {
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

        $renderNotFound = static function () use ($panelRequestContext): void {
            $panelRequestContext()->renderPanelNotFound();
        };

        $renderPublicNotFound = static function () use ($rvn, $root): void {
            (new ViewError($rvn['config'], $root))->render404();
        };

        $router = new Router();
        AuthRouter::register($router, $authController);
        DashboardRouter::register($router, $panelDashboardController);
        ContentRouter::register($router, $panelPageListController, $panelPageEditController, $rvn['input'], $renderNotFound);
        ChannelRouter::register($router, $panelChannelListController, $panelChannelEditController, $rvn['input'], $renderNotFound);
        CategoryRouter::register($router, $panelCategoryListController, $panelCategoryEditController, $rvn['input'], $categoryEnabled, $renderNotFound);
        TagRouter::register($router, $panelTagListController, $panelTagEditController, $rvn['input'], $tagEnabled, $renderNotFound);
        RedirectRouter::register($router, $panelRedirectListController, $panelRedirectEditController, $rvn['input'], $renderNotFound);
        UserRouter::register($router, $panelUserListController, $panelUserEditController, $rvn['input'], $renderNotFound);
        GroupRouter::register($router, $panelGroupListController, $panelGroupEditController, $rvn['input'], $renderNotFound);
        LogRouter::register($router, $panelLogsController);
        RoutingRouter::register($router, $panelRoutingController);
        UpdateRouter::register($router, $panelUpdateController);
        PreferencesRouter::register($router, $panelPreferencesController);
        ConfigRouter::register($router, $panelConfigController);
        SystemRouter::register($router, $panelSystemController);

        ExtensionRouter::register(
            $router,
            $rvn,
            $enabledExtensions,
            $enabledExtensionManifests,
            $extensionPermissionCatalog,
            $internalPath,
            $renderPublicNotFound
        );

        $method = $requestMethod;
        $profilerSettings = OutputProfilerConfigResolver::fromConfig($rvn['config']);
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

        OutputProfilerResponseHook::arm(
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

        $dispatchResult = $router->dispatch(new Request($method, $internalPath));
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
