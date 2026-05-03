<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Panel/ControllerFactories.php
 * Panel controller-factory closure wiring extracted from panel runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Panel;

use Closure;
use Raven\Core\Controller\Panel\AuthController;
use Raven\Core\Controller\Panel\CategoryEditController;
use Raven\Core\Controller\Panel\CategoryListController;
use Raven\Core\Controller\Panel\ChannelEditController;
use Raven\Core\Controller\Panel\ChannelListController;
use Raven\Core\Controller\Panel\ConfigController;
use Raven\Core\Controller\Panel\DashboardController;
use Raven\Core\Controller\Panel\ExtensionController;
use Raven\Core\Controller\Panel\GroupEditController;
use Raven\Core\Controller\Panel\GroupListController;
use Raven\Core\Controller\Panel\LogsController;
use Raven\Core\Controller\Panel\PageEditController;
use Raven\Core\Controller\Panel\PageListController;
use Raven\Core\Controller\Panel\PreferencesController;
use Raven\Core\Controller\Panel\RedirectEditController;
use Raven\Core\Controller\Panel\RedirectListController;
use Raven\Core\Controller\Panel\RoutingController;
use Raven\Core\Controller\Panel\SharedController;
use Raven\Core\Controller\Panel\TagEditController;
use Raven\Core\Controller\Panel\TagListController;
use Raven\Core\Controller\Panel\ThemeController;
use Raven\Core\Controller\Panel\UpdateController;
use Raven\Core\Controller\Panel\UserEditController;
use Raven\Core\Controller\Panel\UserInviteController;
use Raven\Core\Controller\Panel\UserListController;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Auth\Panel\PermissionDefinitionCatalog;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Extension\Panel\Manager as ExtensionManager;
use Raven\Lib\Media\Panel\TaxonomyImageService;
use Raven\Lib\Media\Panel\UserMediaPathService;
use Raven\Lib\Parser\FeedParser;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Scribe\MediaScribe;
use Raven\Lib\Scribe\UserMediaScribe;
use Raven\Lib\Security\PasswordValidator;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Error as ViewError;
use Raven\Lib\View\Form2fa;
use Raven\Lib\Media\Panel\MediaConfigService;
use Raven\Lib\View\Public\ThemeCatalog;

/**
 * Registers panel request-context and base controller factory closures.
 */
final class ControllerFactories
{
    /**
     * Registers base panel closures used by entry orchestration and controller factories.
     *
     * @param array<string, mixed> $rvn Shared runtime container, mutated in-place.
     * @param callable(): AuthService $resolveAuth Lazy auth-service resolver.
     * @param callable(): ExtensionManager $extensionManagerFactory Extension manager factory.
     * @param callable(string): array<int, array{name: string, slug: string}> $extensionFormsProvider Extension enabled-form resolver.
     * @param bool $categoryEnabled Whether category support is enabled for the current request.
     * @param bool $tagEnabled Whether tag support is enabled for the current request.
     * @return void
     */
    public static function registerBase(
        array &$rvn,
        callable $resolveAuth,
        callable $extensionManagerFactory,
        callable $extensionFormsProvider,
        bool $categoryEnabled,
        bool $tagEnabled
    ): void {
        $authController = null;
        $panelSharedController = null;

        /**
         * Builds a session-scoped extension permission map for the current panel user.
         *
         * Guests and unauthenticated requests keep the immutable stock/guest-only
         * permission surface, so extension permission metadata resolves to empty.
         *
         * @param array<int, string> $directoryFilter Optional extension-directory whitelist.
         * @return array<string, array<string, mixed>>
         */
        $rvn['panel_permission_map_provider'] = static function (array $directoryFilter = []) use (
            $resolveAuth,
            $extensionManagerFactory,
            $extensionFormsProvider
        ): array {
            if (($resolveAuth()->userId() ?? null) === null) {
                return [];
            }

            $extensionManager = $extensionManagerFactory();
            return $extensionManager->extensionPermissionMap(
                $directoryFilter,
                static fn (string $extensionPath): array => $extensionManager->readManifest($extensionPath, $extensionFormsProvider)
            );
        };

        /**
         * Builds the auth controller on first use so login routes avoid panel-only dependencies.
         */
        $rvn['auth_controller'] = static function () use (&$authController, $rvn, $resolveAuth): AuthController {
            if ($authController instanceof AuthController) {
                return $authController;
            }

            $authController = new AuthController(
                $rvn['view'],
                $rvn['config'],
                $resolveAuth(),
                $rvn['input'],
                $rvn['csrf']
            );

            return $authController;
        };

        /**
         * Builds the shared request context for split panel sub-controllers.
         */
        $rvn['panel_request_context'] = static function () use (&$panelSharedController, &$rvn, $categoryEnabled, $tagEnabled, $resolveAuth): SharedController {
            if ($panelSharedController instanceof SharedController) {
                return $panelSharedController;
            }

            $panelSharedController = new SharedController(
                $rvn['view'],
                $rvn['config'],
                $resolveAuth(),
                $rvn['csrf'],
                new SessionFlash('_raven_flash'),
                $categoryEnabled,
                $tagEnabled,
                static function () use (&$rvn): void {
                    (new ViewError($rvn['config'], (string) $rvn['root']))->render404();
                }
            );

            return $panelSharedController;
        };
    }

    /**
     * Registers panel content/taxonomy controller closures.
     *
     * @param array<string, mixed> $rvn Shared runtime container, mutated in-place.
     * @param Closure $panelContentDomain Panel content domain aggregate closure.
     * @param Closure $panelTaxonomyDomain Panel taxonomy domain aggregate closure.
     * @param callable(): mixed $extensionStateStoreFactory Extension state store factory.
     * @param callable(): ExtensionManager $extensionManagerFactory Extension manager factory.
     * @param callable(): mixed $extensionContentFactory Extension content catalog factory.
     * @return void
     */
    public static function registerContentTaxonomyControllers(
        array &$rvn,
        Closure $panelContentDomain,
        Closure $panelTaxonomyDomain,
        callable $extensionStateStoreFactory,
        callable $extensionManagerFactory,
        callable $extensionContentFactory
    ): void {
        $dashboardController = null;
        $pageListController = null;
        $pageEditController = null;
        $channelListController = null;
        $channelEditController = null;
        $categoryListController = null;
        $categoryEditController = null;
        $redirectListController = null;
        $redirectEditController = null;
        $tagListController = null;
        $tagEditController = null;

        /**
         * Builds the split dashboard controller on first use.
         */
        $rvn['panel_dashboard_controller'] = static function () use (&$dashboardController, &$rvn): DashboardController {
            if ($dashboardController instanceof DashboardController) {
                return $dashboardController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $dashboardController = new DashboardController($requestContextFactory());
            return $dashboardController;
        };

        /**
         * Builds the page list controller on first use.
         * Owns GET /page only.
         */
        $rvn['panel_page_list_controller'] = static function () use (
            &$pageListController,
            &$rvn,
            $panelContentDomain
        ): PageListController {
            if ($pageListController instanceof PageListController) {
                return $pageListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $contentDomain = $panelContentDomain();
            $pageListController = new PageListController(
                $requestContextFactory(),
                $rvn['input'],
                $contentDomain['page_read'],
                $contentDomain['channel_read']
            );

            return $pageListController;
        };

        /**
         * Builds the page edit controller on first use.
         * Owns page create/edit, save, gallery upload/delete, and page delete.
         */
        $rvn['panel_page_edit_controller'] = static function () use (
            &$pageEditController,
            &$rvn,
            $panelContentDomain,
            $panelTaxonomyDomain,
            $extensionStateStoreFactory,
            $extensionManagerFactory,
            $extensionContentFactory
        ): PageEditController {
            if ($pageEditController instanceof PageEditController) {
                return $pageEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $contentDomain = $panelContentDomain();
            $taxonomyDomain = $panelTaxonomyDomain();
            $pageEditController = new PageEditController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                $contentDomain['page_read'],
                $contentDomain['page_write'],
                $contentDomain['media_read'],
                $contentDomain['media_write'],
                $contentDomain['media_manager'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['taxonomy_lookup'],
                $contentDomain['user_read'],
                $contentDomain['channel_read'],
                $rvn['panel_editor_tabs'](),
                $rvn['panel_editor'](),
                $rvn['panel_editor_blocks'](),
                $rvn['panel_editor_mce'](),
                $rvn['panel_editor_mde'](),
                $extensionStateStoreFactory(),
                $extensionManagerFactory(),
                $extensionContentFactory(),
                is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => []
            );

            return $pageEditController;
        };

        /**
         * Builds the channel list controller on first use.
         * Owns GET /channel only.
         */
        $rvn['panel_channel_list_controller'] = static function () use (&$channelListController, &$rvn, $panelTaxonomyDomain): ChannelListController {
            if ($channelListController instanceof ChannelListController) {
                return $channelListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $channelListController = new ChannelListController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['channel_read']
            );

            return $channelListController;
        };

        /**
         * Builds the channel edit controller on first use.
         * Owns channel create/edit, save, and delete routes.
         */
        $rvn['panel_channel_edit_controller'] = static function () use (&$channelEditController, &$rvn, $panelTaxonomyDomain): ChannelEditController {
            if ($channelEditController instanceof ChannelEditController) {
                return $channelEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $channelEditController = new ChannelEditController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['channel_read'],
                $taxonomyDomain['channel_write'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['category_enabled'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config']),
                new MediaScribe($rvn['db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], (string) $rvn['root']),
                new FeedParser($rvn['config'], $rvn['input']),
                $rvn['panel_editor_tabs'](),
                $rvn['panel_editor'](),
                new Upload()
            );

            return $channelEditController;
        };

        /**
         * Builds the category list controller on first use.
         * Owns GET /category and GET /category/set only.
         */
        $rvn['panel_category_list_controller'] = static function () use (&$categoryListController, &$rvn, $panelTaxonomyDomain): CategoryListController {
            if ($categoryListController instanceof CategoryListController) {
                return $categoryListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $categoryListController = new CategoryListController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['category_enabled'],
                $taxonomyDomain['channel_read']
            );

            return $categoryListController;
        };

        /**
         * Builds the category edit controller on first use.
         * Owns category create/edit, save, delete, and category-set CRUD routes.
         */
        $rvn['panel_category_edit_controller'] = static function () use (&$categoryEditController, &$rvn, $panelTaxonomyDomain): CategoryEditController {
            if ($categoryEditController instanceof CategoryEditController) {
                return $categoryEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $categoryEditController = new CategoryEditController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_write'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['category_set_write'],
                $taxonomyDomain['category_enabled'],
                new TaxonomyImageService($rvn['config']),
                new MediaScribe($rvn['db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], (string) $rvn['root']),
                $taxonomyDomain['channel_read'],
                $rvn['panel_editor_tabs'](),
                new Upload()
            );

            return $categoryEditController;
        };

        /**
         * Builds the redirect list controller on first use.
         * Owns GET /redirect only.
         */
        $rvn['panel_redirect_list_controller'] = static function () use (&$redirectListController, &$rvn, $panelTaxonomyDomain): RedirectListController {
            if ($redirectListController instanceof RedirectListController) {
                return $redirectListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $redirectListController = new RedirectListController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['redirect_read']
            );

            return $redirectListController;
        };

        /**
         * Builds the redirect edit controller on first use.
         * Owns redirect create/edit, save, and delete routes.
         */
        $rvn['panel_redirect_edit_controller'] = static function () use (&$redirectEditController, &$rvn, $panelTaxonomyDomain): RedirectEditController {
            if ($redirectEditController instanceof RedirectEditController) {
                return $redirectEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $redirectEditController = new RedirectEditController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['channel_read'],
                $taxonomyDomain['redirect_read'],
                $taxonomyDomain['redirect_write'],
            );

            return $redirectEditController;
        };

        /**
         * Builds the tag list controller on first use.
         * Owns GET /tag and GET /tag/set only.
         */
        $rvn['panel_tag_list_controller'] = static function () use (&$tagListController, &$rvn, $panelTaxonomyDomain): TagListController {
            if ($tagListController instanceof TagListController) {
                return $tagListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $tagListController = new TagListController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['tag_enabled'],
                $taxonomyDomain['channel_read']
            );

            return $tagListController;
        };

        /**
         * Builds the tag edit controller on first use.
         * Owns tag create/edit, save, delete, and tag-set CRUD routes.
         */
        $rvn['panel_tag_edit_controller'] = static function () use (&$tagEditController, &$rvn, $panelTaxonomyDomain): TagEditController {
            if ($tagEditController instanceof TagEditController) {
                return $tagEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $tagEditController = new TagEditController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_write'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['tag_set_write'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config']),
                new MediaScribe($rvn['db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], (string) $rvn['root']),
                $taxonomyDomain['channel_read'],
                $rvn['panel_editor_tabs'](),
                new Upload()
            );

            return $tagEditController;
        };
    }

    /**
     * Registers panel user/preferences/system controller closures.
     *
     * @param array<string, mixed> $rvn Shared runtime container, mutated in-place.
     * @param Closure $panelUserDomain Panel user/group domain aggregate closure.
     * @param Closure $panelSystemDomain Panel system/routing domain aggregate closure.
     * @param callable(): mixed $loggerFactory Event-log service factory.
     * @param callable(): ThemeCatalog $themeCatalogFactory Public-theme catalog service factory.
     * @param callable(): mixed $extensionStateStoreFactory Extension state store factory.
     * @param callable(): ExtensionManager $extensionManagerFactory Extension manager factory.
     * @return void
     */
    public static function registerUserAdminControllers(
        array &$rvn,
        Closure $panelUserDomain,
        Closure $panelSystemDomain,
        callable $loggerFactory,
        callable $themeCatalogFactory,
        callable $extensionStateStoreFactory,
        callable $extensionManagerFactory
    ): void {
        $userListController = null;
        $userEditController = null;
        $userInviteController = null;
        $groupListController = null;
        $groupEditController = null;
        $preferencesController = null;
        $logsController = null;
        $routingController = null;
        $updateController = null;
        $configController = null;
        $themeController = null;
        $extensionController = null;

        /**
         * Builds the user list controller on first use.
         * Owns GET /user and GET /user/invites.
         */
        $rvn['panel_user_list_controller'] = static function () use (&$userListController, &$rvn, $panelUserDomain): UserListController {
            if ($userListController instanceof UserListController) {
                return $userListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $userDomain = $panelUserDomain();
            $userListController = new UserListController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                $userDomain['group_read'],
                $userDomain['user_read'],
                $userDomain['invite_read'],
                new SessionFlash('_raven_flash_list'),
                new GroupRouteParser($rvn['config'], $rvn['input']),
                new LoginIdentifier()
            );

            return $userListController;
        };

        /**
         * Builds the user edit controller on first use.
         * Owns user create/edit, save, and delete routes.
         */
        $rvn['panel_user_edit_controller'] = static function () use (&$userEditController, &$rvn, $panelUserDomain): UserEditController {
            if ($userEditController instanceof UserEditController) {
                return $userEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $userDomain = $panelUserDomain();
            $userEditController = new UserEditController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $userDomain['group_read'],
                $userDomain['user_read'],
                $userDomain['user_write'],
                new GroupRouteParser($rvn['config'], $rvn['input']),
                new LoginIdentifier(),
                $rvn['panel_editor_tabs'](),
                $rvn['panel_editor'](),
                $rvn['panel_editor_blocks'](),
                new MediaConfigService($rvn['config']),
                new UserProfileParser($rvn['input']),
                new Form2fa($rvn['input']),
                new UserMediaScribe((string) $rvn['root']),
                new UserMediaPathService()
            );

            return $userEditController;
        };

        /**
         * Builds the user invite controller on first use.
         * Owns invite token create/generate/delete routes.
         */
        $rvn['panel_user_invite_controller'] = static function () use (&$userInviteController, &$rvn, $panelUserDomain): UserInviteController {
            if ($userInviteController instanceof UserInviteController) {
                return $userInviteController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $userDomain = $panelUserDomain();
            $userInviteController = new UserInviteController(
                $requestContextFactory(),
                $rvn['input'],
                $userDomain['invite_write'],
                new SessionFlash('_raven_flash_list'),
                new GroupRouteParser($rvn['config'], $rvn['input'])
            );

            return $userInviteController;
        };

        /**
         * Builds the group list controller on first use.
         * Owns GET /group only.
         */
        $rvn['panel_group_list_controller'] = static function () use (&$groupListController, &$rvn, $panelUserDomain): GroupListController {
            if ($groupListController instanceof GroupListController) {
                return $groupListController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $groupDomain = $panelUserDomain();
            $groupListController = new GroupListController(
                $requestContextFactory(),
                $rvn['input'],
                $groupDomain['group_read'],
                new GroupRouteParser($rvn['config'], $rvn['input'])
            );

            return $groupListController;
        };

        /**
         * Builds the group edit controller on first use.
         * Owns group create/edit, save, and delete routes.
         */
        $rvn['panel_group_edit_controller'] = static function () use (&$groupEditController, &$rvn, $panelUserDomain): GroupEditController {
            if ($groupEditController instanceof GroupEditController) {
                return $groupEditController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $groupDomain = $panelUserDomain();
            $groupEditController = new GroupEditController(
                $requestContextFactory(),
                $rvn['input'],
                $groupDomain['group_write'],
                $groupDomain['group_read'],
                new GroupRouteParser($rvn['config'], $rvn['input']),
                $rvn['panel_editor_tabs'](),
                $rvn['panel_editor'](),
                new TaxonomyImageService($rvn['config']),
                new MediaScribe($rvn['db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], (string) $rvn['root']),
                new PermissionDefinitionCatalog(),
                new Upload(),
                static function () use (&$rvn): array {
                    $provider = $rvn['panel_permission_map_provider'] ?? null;
                    if (!is_callable($provider)) {
                        return [];
                    }

                    $map = $provider();
                    return is_array($map) ? $map : [];
                }
            );

            return $groupEditController;
        };

        /**
         * Builds the split preferences controller on first use.
         */
        $rvn['panel_preferences_controller'] = static function () use (&$preferencesController, &$rvn): PreferencesController {
            if ($preferencesController instanceof PreferencesController) {
                return $preferencesController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $preferencesController = new PreferencesController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                new LoginIdentifier(),
                $rvn['panel_editor_tabs'](),
                $rvn['panel_editor'](),
                $rvn['panel_editor_blocks'](),
                new MediaConfigService($rvn['config']),
                new UserProfileParser($rvn['input']),
                new Form2fa($rvn['input']),
                new UserMediaScribe((string) $rvn['root']),
                new UserMediaPathService(),
                new PasswordValidator()
            );

            return $preferencesController;
        };

        /**
         * Builds the split logs controller on first use.
         * Owns `/logs*` only.
         */
        $rvn['panel_logs_controller'] = static function () use (&$logsController, &$rvn, $loggerFactory): LogsController {
            if ($logsController instanceof LogsController) {
                return $logsController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $logsController = new LogsController(
                $requestContextFactory(),
                $rvn['input'],
                $loggerFactory
            );

            return $logsController;
        };

        /**
         * Builds the split routing controller on first use.
         * Owns `/routing*` only.
         */
        $rvn['panel_routing_controller'] = static function () use (&$routingController, &$rvn, $panelSystemDomain, $themeCatalogFactory): RoutingController {
            if ($routingController instanceof RoutingController) {
                return $routingController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $systemDomain = $panelSystemDomain();
            $routingController = new RoutingController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $systemDomain['channel'],
                $systemDomain['page'],
                $systemDomain['redirect'],
                $systemDomain['user'],
                $systemDomain['taxonomy_lookup'],
                $themeCatalogFactory()
            );

            return $routingController;
        };

        /**
         * Builds the split update controller on first use.
         * Owns `/update*` only.
         */
        $rvn['panel_update_controller'] = static function () use (
            &$updateController,
            &$rvn,
            $extensionManagerFactory,
            $themeCatalogFactory
        ): UpdateController {
            if ($updateController instanceof UpdateController) {
                return $updateController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $updateController = new UpdateController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $themeCatalogFactory()->stockSlugs(),
                $extensionManagerFactory()->stockExtensionDirectories()
            );

            return $updateController;
        };

        /**
         * Builds the split configuration controller on first use.
         * Owns `/configuration` and `/configuration/save` only.
         */
        $rvn['panel_config_controller'] = static function () use (&$configController, &$rvn, $panelSystemDomain, $themeCatalogFactory): ConfigController {
            if ($configController instanceof ConfigController) {
                return $configController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $systemDomain = $panelSystemDomain();
            $configController = new ConfigController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                $systemDomain['channel'],
                $systemDomain['category_set'],
                $systemDomain['tag_set'],
                $rvn['panel_editor_tabs'](),
                $rvn['panel_editor'](),
                $rvn['panel_editor_blocks'](),
                $themeCatalogFactory()
            );

            return $configController;
        };

        /**
         * Builds the split theme controller on first use.
         * Owns `/themes*` only.
         */
        $rvn['panel_theme_controller'] = static function () use (&$themeController, &$rvn, $themeCatalogFactory): ThemeController {
            if ($themeController instanceof ThemeController) {
                return $themeController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $themeController = new ThemeController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $themeCatalogFactory()
            );
            return $themeController;
        };

        /**
         * Builds the split extension controller on first use.
         * Owns `/extensions*` only.
         */
        $rvn['panel_extension_controller'] = static function () use (
            &$extensionController,
            &$rvn,
            $extensionStateStoreFactory,
            $extensionManagerFactory
        ): ExtensionController {
            if ($extensionController instanceof ExtensionController) {
                return $extensionController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $extensionController = new ExtensionController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $extensionStateStoreFactory(),
                $extensionManagerFactory(),
                is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => []
            );
            return $extensionController;
        };
    }

}
