<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Routing/Panel/PanelRuntimeBuilder.php
 * Panel runtime assembly on top of the shared core bootstrap.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Closure;
use Raven\Controller\AuthController;
use Raven\Controller\Panel\ContentController;
use Raven\Controller\Panel\DashboardController;
use Raven\Controller\Panel\GroupController;
use Raven\Controller\Panel\PreferencesController;
use Raven\Controller\Panel\RedirectController;
use Raven\Controller\Panel\RequestContext;
use Raven\Controller\Panel\SystemController;
use Raven\Controller\Panel\TaxonomyController;
use Raven\Controller\Panel\UserController;
use Raven\Core\Media\PageImageManager;
use Raven\Core\View;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\PanelInvitePolicyService;
use Raven\Lib\Auth\PanelPermissionDefinitionCatalog;
use Raven\Lib\Auth\PanelTwoFactorPreferencesService;
use Raven\Lib\Auth\PasswordChangePolicy;
use Raven\Lib\Config\Config;
use Raven\Lib\Config\PanelMediaConfigService;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Http\UploadFileSetNormalizer;
use Raven\Lib\Log\EventLogger;
use Raven\Lib\Media\AvatarUploadService;
use Raven\Lib\Media\TaxonomyImageService;
use Raven\Lib\Media\UserMediaPathService;
use Raven\Lib\Panel\PanelEditorTabService;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\RouteConfigService;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Repository\CategoryRepository;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomyLookupRepository;
use Raven\Repository\TaxonomySetRepository;
use Raven\Repository\UserRepository;

/**
 * Builds panel-scope runtime factories on top of the shared Raven container.
 *
 * Keep this builder limited to wiring that is broadly needed across panel
 * requests. Route-family behavior should keep moving into panel routing
 * registrars and the split panel sub-controllers.
 */
final class PanelRuntimeBuilder
{
    /**
     * Enriches the shared core container with panel-runtime factories.
     *
     * @param array<string, mixed> $rvn Shared core bootstrap container.
     * @return array<string, mixed> Panel-enriched runtime container.
     */
    public static function build(array $rvn): array
    {
        if (!isset($rvn['root'], $rvn['db'], $rvn['auth_db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], $rvn['auth'], $rvn['input'], $rvn['csrf'])) {
            return $rvn;
        }

        if (is_callable($rvn['auth_db'])) {
            $rvn['auth_db'] = $rvn['auth_db']();
        }
        if (is_callable($rvn['auth'])) {
            $rvn['auth'] = $rvn['auth']();
        }

        $authController = null;
        $contentController = null;
        $dashboardController = null;
        $groupController = null;
        $preferencesController = null;
        $panelRequestContext = null;
        $panelRuntime = null;
        $redirectController = null;
        $systemController = null;
        $taxonomyController = null;
        $userController = null;
        $categorySetRepository = null;
        $tagSetRepository = null;
        $inviteTokenRepository = null;
        $pageImageManager = null;
        $logger = null;
        $categoryRepository = null;
        $tagRepository = null;
        $taxonomyLookupRepository = null;
        $channelRepository = null;
        $groupRepository = null;
        $pageImageRepository = null;
        $pageRepository = null;
        $redirectRepository = null;
        $userRepository = null;

        $rvn['view'] = new View((string) $rvn['root'] . '/private/tpl');
        $categoryEnabled = Config::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = Config::bool($rvn['config']->get('tag.enabled', true), true);

        /**
         * Request-scoped memoization keeps bootstrap factories lightweight while
         * avoiding repeated repo construction within one request.
         *
         * @param callable(): mixed $builder Builder for one runtime value.
         * @return Closure Memoized factory that resolves the value once per request.
         */
        $memoize = static function (callable $builder): Closure {
            $resolved = false;
            $value = null;

            return static function () use (&$resolved, &$value, $builder): mixed {
                if ($resolved) {
                    return $value;
                }

                $value = $builder();
                $resolved = true;
                return $value;
            };
        };

        /**
         * Builds channel storage for panel content and routing flows.
         */
        $channelFactory = $memoize(static function () use (&$channelRepository, $rvn): ChannelRepository {
            $channelRepository = new ChannelRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                (string) $rvn['root'] . '/private/dat/channel'
            );

            return $channelRepository;
        });

        /**
         * Builds group storage for panel account/group management flows.
         */
        $groupFactory = $memoize(static function () use (&$groupRepository, $rvn): GroupRepository {
            $groupRepository = new GroupRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $groupRepository;
        });

        /**
         * Builds page-image storage for panel content/media flows.
         */
        $pageImagesFactory = $memoize(static function () use (&$pageImageRepository, $rvn): PageImageRepository {
            $pageImageRepository = new PageImageRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $pageImageRepository;
        });

        /**
         * Builds page storage for panel content/routing flows.
         */
        $pageFactory = $memoize(static function () use (&$pageRepository, $rvn, $channelFactory, $categoryEnabled, $tagEnabled): PageRepository {
            $pageRepository = new PageRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelFactory(),
                $categoryEnabled,
                $tagEnabled
            );

            return $pageRepository;
        });

        /**
         * Builds redirect storage for panel routing management flows.
         */
        $redirectFactory = $memoize(static function () use (&$redirectRepository, $rvn, $channelFactory): RedirectRepository {
            $redirectRepository = new RedirectRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelFactory()
            );

            return $redirectRepository;
        });

        /**
         * Builds user storage for panel user/preferences flows.
         */
        $userFactory = $memoize(static function () use (&$userRepository, $rvn): UserRepository {
            $userRepository = new UserRepository(
                $rvn['auth_db'],
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $userRepository;
        });

        /**
         * Builds the file-backed category set repository only for panel taxonomy editors.
         */
        $categorySetFactory = $memoize(static function () use (&$categorySetRepository, $rvn): TaxonomySetRepository {
            $categorySetRepository = new TaxonomySetRepository('category', (string) $rvn['root'] . '/private/dat/category-set');
            return $categorySetRepository;
        });

        /**
         * Builds the file-backed tag set repository only for panel taxonomy editors.
         */
        $tagSetFactory = $memoize(static function () use (&$tagSetRepository, $rvn): TaxonomySetRepository {
            $tagSetRepository = new TaxonomySetRepository('tag', (string) $rvn['root'] . '/private/dat/tag-set');
            return $tagSetRepository;
        });

        /**
         * Builds invite-token storage only for panel invite management.
         */
        $inviteTokenFactory = $memoize(static function () use (&$inviteTokenRepository, $rvn): InviteTokenRepository {
            $inviteTokenRepository = new InviteTokenRepository(
                $rvn['auth_db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $inviteTokenRepository;
        });

        /**
         * Builds the panel page-image helper only when page editing enters media flows.
         */
        $pageImageManagerFactory = $memoize(static function () use (&$pageImageManager, $rvn, $pageImagesFactory): PageImageManager {
            $pageImageManager = new PageImageManager($rvn['config'], $rvn['input'], $pageImagesFactory(), (string) $rvn['root']);

            return $pageImageManager;
        });

        /**
         * Builds panel log storage only for routes that touch the event log UI.
         */
        $loggerFactory = $memoize(static function () use (&$logger, $rvn): EventLogger {
            $logger = new EventLogger(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                (array) $rvn['config']->get('logging', [])
            );

            return $logger;
        });

        /**
         * Builds category storage only for panel taxonomy flows that actually use categories.
         */
        $categoryFactory = $memoize(static function () use (&$categoryRepository, $rvn): CategoryRepository {
            $categoryRepository = new CategoryRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $categoryRepository;
        });

        /**
         * Builds tag storage only for panel taxonomy flows that actually use tags.
         */
        $tagFactory = $memoize(static function () use (&$tagRepository, $rvn): TagRepository {
            $tagRepository = new TagRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $tagRepository;
        });

        /**
         * Builds taxonomy lookup storage only for routing and page-editor flows
         * that need category/tag option lookups beyond channel routing.
         */
        $taxonomyLookupFactory = $memoize(static function () use (&$taxonomyLookupRepository, $rvn, $channelFactory): TaxonomyLookupRepository {
            $taxonomyLookupRepository = new TaxonomyLookupRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelFactory()
            );

            return $taxonomyLookupRepository;
        });

        /**
         * Content routes share page/channel/media/user dependencies mapped to the
         * panel content sub-controller. User storage is included because page-editor
         * author validation and author select options need the user repository.
         *
         * @return array<string, mixed>
         */
        $panelContentDomain = $memoize(static function () use (
            $channelFactory,
            $pageFactory,
            $pageImagesFactory,
            $pageImageManagerFactory,
            $userFactory
        ): array {
            return [
                'channel' => $channelFactory(),
                'page' => $pageFactory(),
                'page_images' => $pageImagesFactory(),
                'page_image_manager' => $pageImageManagerFactory,
                'user' => $userFactory(),
            ];
        });

        /**
         * Taxonomy routes share channel/routing deps plus lazy category/tag
         * resolvers, matching the future taxonomy sub-controller seam.
         *
         * @return array<string, mixed>
         */
        $panelTaxonomyDomain = $memoize(static function () use (
            $channelFactory,
            $redirectFactory,
            $categoryFactory,
            $categorySetFactory,
            $tagFactory,
            $tagSetFactory,
            $taxonomyLookupFactory,
            $categoryEnabled,
            $tagEnabled
        ): array {
            return [
                'channel' => $channelFactory(),
                'redirect' => $redirectFactory(),
                'category' => $categoryFactory,
                'category_set' => $categorySetFactory,
                'tag' => $tagFactory,
                'tag_set' => $tagSetFactory,
                'taxonomy_lookup' => $taxonomyLookupFactory,
                'category_enabled' => $categoryEnabled,
                'tag_enabled' => $tagEnabled,
            ];
        });

        /**
         * User/group/invite deps stay clustered so account-facing routes can split
         * away from the monolith without another bootstrap rewrite.
         *
         * @return array<string, mixed>
         */
        $panelUserDomain = $memoize(static function () use ($groupFactory, $userFactory, $inviteTokenFactory): array {
            return [
                'group' => $groupFactory(),
                'user' => $userFactory(),
                'invite_tokens' => $inviteTokenFactory,
            ];
        });

        /**
         * Preferences currently share the user/group account seam.
         *
         * @return array<string, mixed>
         */
        $panelPreferencesDomain = $panelUserDomain;

        /**
         * System routes currently need logging plus the same routing/content seams.
         *
         * @return array<string, mixed>
         */
        $panelSystemDomain = $memoize(static function () use (
            $channelFactory,
            $categorySetFactory,
            $pageFactory,
            $redirectFactory,
            $tagSetFactory,
            $taxonomyLookupFactory,
            $userFactory,
            $loggerFactory
        ): array {
            return [
                'channel' => $channelFactory(),
                'category_set' => $categorySetFactory,
                'page' => $pageFactory(),
                'redirect' => $redirectFactory(),
                'tag_set' => $tagSetFactory,
                'taxonomy_lookup' => $taxonomyLookupFactory,
                'user' => $userFactory(),
                'logger' => $loggerFactory,
            ];
        });

        $rvn['panel_domain_content'] = $panelContentDomain;
        $rvn['panel_domain_taxonomy'] = $panelTaxonomyDomain;
        $rvn['panel_domain_user'] = $panelUserDomain;
        $rvn['panel_domain_group'] = $panelUserDomain;
        $rvn['panel_domain_preferences'] = $panelPreferencesDomain;
        $rvn['panel_domain_system'] = $panelSystemDomain;

        /**
         * Builds a session-scoped extension permission map for the current panel user.
         *
         * Guests and unauthenticated requests keep the immutable stock/guest-only
         * permission surface, so extension permission metadata resolves to empty.
         *
         * @return array<string, array<string, mixed>>
         */
        $rvn['panel_permission_map_provider'] = static function () use (&$rvn): array {
            if (($rvn['auth']->userId() ?? null) === null) {
                return [];
            }

            $systemControllerFactory = $rvn['panel_system_controller'] ?? null;
            if (is_callable($systemControllerFactory)) {
                return $systemControllerFactory()->extensionPanelPermissionMapForDirectories();
            }

            return [];
        };

        /**
         * Builds the auth controller on first use so login routes avoid panel-only dependencies.
         */
        $rvn['auth_controller'] = static function () use (&$authController, $rvn): AuthController {
            if ($authController instanceof AuthController) {
                return $authController;
            }

            $authController = new AuthController(
                $rvn['view'],
                $rvn['config'],
                $rvn['auth'],
                $rvn['input'],
                $rvn['csrf']
            );

            return $authController;
        };

        /**
         * Builds the shared request context for split panel sub-controllers.
         */
        $rvn['panel_request_context'] = static function () use (&$panelRequestContext, &$systemController, &$rvn, $categoryEnabled, $tagEnabled): RequestContext {
            if ($panelRequestContext instanceof RequestContext) {
                return $panelRequestContext;
            }

            $panelRequestContext = new RequestContext(
                $rvn['view'],
                $rvn['config'],
                $rvn['auth'],
                $rvn['csrf'],
                new SessionFlash('_raven_flash'),
                $categoryEnabled,
                $tagEnabled,
                static function () use (&$systemController, &$rvn): void {
                    if ($systemController instanceof SystemController) {
                        $systemController->renderPublicNotFound();
                        return;
                    }

                    $systemControllerFactory = $rvn['panel_system_controller'] ?? null;
                    if (is_callable($systemControllerFactory)) {
                        $systemControllerFactory()->renderPublicNotFound();
                        return;
                    }

                    http_response_code(404);
                    echo 'Not Found';
                }
            );

            return $panelRequestContext;
        };

        /**
         * Builds the split dashboard controller on first use.
         */
        $rvn['panel_dashboard_controller'] = static function () use (&$dashboardController, &$rvn): DashboardController {
            if ($dashboardController instanceof DashboardController) {
                return $dashboardController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $dashboardController = new DashboardController($requestContextFactory());
            return $dashboardController;
        };

        /**
         * Builds the split content controller on first use.
         * Owns page list, create/edit, save, gallery upload/delete, and page delete.
         */
        $rvn['panel_content_controller'] = static function () use (&$contentController, &$rvn, $panelContentDomain, $panelTaxonomyDomain): ContentController {
            if ($contentController instanceof ContentController) {
                return $contentController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $contentDomain = $panelContentDomain();
            $taxonomyDomain = $panelTaxonomyDomain();
            $contentController = new ContentController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                $contentDomain['page'],
                $contentDomain['channel'],
                $contentDomain['page_images'],
                $contentDomain['page_image_manager'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['taxonomy_lookup'],
                $contentDomain['user'],
                new RouteConfigService($rvn['config'], $rvn['input']),
                new PanelEditorTabService($rvn['input']),
                is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => []
            );

            return $contentController;
        };

        /**
         * Builds the split redirect controller on first use.
         * Redirect CRUD only needs channel validation and redirect storage.
         */
        $rvn['panel_redirect_controller'] = static function () use (&$redirectController, &$rvn, $panelTaxonomyDomain): RedirectController {
            if ($redirectController instanceof RedirectController) {
                return $redirectController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $redirectController = new RedirectController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['channel'],
                $taxonomyDomain['redirect']
            );

            return $redirectController;
        };

        /**
         * Builds the split taxonomy controller on first use.
         * Owns channel, category, category-set, tag, and tag-set management routes.
         */
        $rvn['panel_taxonomy_controller'] = static function () use (&$taxonomyController, &$rvn, $panelTaxonomyDomain): TaxonomyController {
            if ($taxonomyController instanceof TaxonomyController) {
                return $taxonomyController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $taxonomyController = new TaxonomyController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['channel'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['category_enabled'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config'], (string) $rvn['root']),
                new RouteConfigService($rvn['config'], $rvn['input']),
                new PanelEditorTabService($rvn['input']),
                new UploadFileSetNormalizer()
            );

            return $taxonomyController;
        };

        /**
         * Builds the split user controller on first use.
         */
        $rvn['panel_user_controller'] = static function () use (&$userController, &$rvn, $panelUserDomain): UserController {
            if ($userController instanceof UserController) {
                return $userController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $userDomain = $panelUserDomain();
            $userController = new UserController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $userDomain['group'],
                $userDomain['user'],
                $userDomain['invite_tokens'],
                new SessionFlash('_raven_flash_list'),
                new RouteConfigService($rvn['config'], $rvn['input']),
                new PanelInvitePolicyService($rvn['input']),
                new LoginIdentifierResolver(),
                new PanelEditorTabService($rvn['input']),
                new PanelMediaConfigService($rvn['config']),
                new ProfileContactService($rvn['input']),
                new PanelTwoFactorPreferencesService($rvn['input']),
                new AvatarUploadService(),
                new UserMediaPathService()
            );

            return $userController;
        };

        /**
         * Builds the split group controller on first use.
         */
        $rvn['panel_group_controller'] = static function () use (&$groupController, &$rvn, $panelUserDomain): GroupController {
            if ($groupController instanceof GroupController) {
                return $groupController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $groupDomain = $panelUserDomain();
            $groupController = new GroupController(
                $requestContextFactory(),
                $rvn['input'],
                $groupDomain['group'],
                new RouteConfigService($rvn['config'], $rvn['input']),
                new PanelEditorTabService($rvn['input']),
                new TaxonomyImageService($rvn['config'], (string) $rvn['root']),
                new PanelPermissionDefinitionCatalog(),
                new UploadFileSetNormalizer(),
                static function () use (&$rvn): array {
                    $provider = $rvn['panel_permission_map_provider'] ?? null;
                    if (!is_callable($provider)) {
                        return [];
                    }

                    $map = $provider();
                    return is_array($map) ? $map : [];
                }
            );

            return $groupController;
        };

        /**
         * Builds the split preferences controller on first use.
         */
        $rvn['panel_preferences_controller'] = static function () use (&$preferencesController, &$rvn): PreferencesController {
            if ($preferencesController instanceof PreferencesController) {
                return $preferencesController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $preferencesController = new PreferencesController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                new LoginIdentifierResolver(),
                new PanelEditorTabService($rvn['input']),
                new PanelMediaConfigService($rvn['config']),
                new ProfileContactService($rvn['input']),
                new PanelTwoFactorPreferencesService($rvn['input']),
                new AvatarUploadService(),
                new UserMediaPathService(),
                new PasswordChangePolicy()
            );

            return $preferencesController;
        };

        /**
         * Builds the split system controller on first use.
         * Owns configuration, update, routing, logs, themes, and extensions.
         */
        $rvn['panel_system_controller'] = static function () use (&$systemController, &$rvn, $panelSystemDomain): SystemController {
            if ($systemController instanceof SystemController) {
                return $systemController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $systemDomain = $panelSystemDomain();
            $systemController = new SystemController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $systemDomain['channel'],
                $systemDomain['page'],
                $systemDomain['redirect'],
                $systemDomain['user'],
                $systemDomain['category_set'],
                $systemDomain['tag_set'],
                $systemDomain['taxonomy_lookup'],
                $systemDomain['logger'],
                is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => []
            );

            return $systemController;
        };

        /**
         * Builds panel-only repositories, extension services, and route-registration data on demand.
         *
         * @return array<string, mixed>
         */
        $rvn['initialize_panel_runtime'] = static function () use (
            &$panelRuntime,
            &$rvn,
            $panelContentDomain,
            $panelTaxonomyDomain,
            $panelUserDomain,
            $panelSystemDomain,
            $categoryEnabled,
            $tagEnabled
        ): array {
            if (is_array($panelRuntime)) {
                return $rvn + $panelRuntime;
            }

            $siteContextBuilder = new SiteContextBuilder();
            $contentDomain = $panelContentDomain();
            $taxonomyDomain = $panelTaxonomyDomain();
            $userDomain = $panelUserDomain();
            $panelSystemDomain();

            // Keep legacy top-level aliases populated during panel runtime so
            // stock and third-party panel routes keep their existing container contract.
            $rvn['channel'] = $contentDomain['channel'];
            $rvn['group'] = $userDomain['group'];
            $rvn['page_images'] = $contentDomain['page_images'];
            $rvn['page'] = $contentDomain['page'];
            $rvn['redirect'] = $taxonomyDomain['redirect'];
            $rvn['user'] = $userDomain['user'];

            // Panel route files depend on this closure, so populate it only when panel runtime is active.
            $rvn['panel_site_data'] = static function (bool $includeDomain = true) use ($siteContextBuilder, $rvn, $categoryEnabled, $tagEnabled): array {
                return $siteContextBuilder->panel($rvn['config'], $categoryEnabled, $tagEnabled, $includeDomain);
            };

            $enabledExtensionManifests = is_array($rvn['enabled_extension_manifests'] ?? null)
                ? (array) $rvn['enabled_extension_manifests']
                : [];
            $enabledExtensions = [];
            foreach (array_keys($enabledExtensionManifests) as $directoryName) {
                if (is_dir((string) $rvn['root'] . '/private/ext/' . $directoryName)) {
                    $enabledExtensions[$directoryName] = true;
                }
            }

            $panelRuntime = [
                'category_enabled' => $categoryEnabled,
                'tag_enabled' => $tagEnabled,
                'enabled_extensions' => $enabledExtensions,
                'enabled_extension_manifests' => $enabledExtensionManifests,
            ];

            return $rvn + $panelRuntime;
        };

        return $rvn;
    }
}
