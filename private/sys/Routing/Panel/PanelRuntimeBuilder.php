<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelRuntimeBuilder.php
 * Panel runtime assembly on top of the shared core bootstrap.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Closure;
use PDO;
use Raven\Core\Controller\Panel\AuthController;
use Raven\Core\Controller\Panel\CategoryController;
use Raven\Core\Controller\Panel\ChannelController;
use Raven\Core\Controller\Panel\ConfigController;
use Raven\Core\Controller\Panel\ContentController;
use Raven\Core\Controller\Panel\DashboardController;
use Raven\Core\Controller\Panel\GroupController;
use Raven\Core\Controller\Panel\PreferencesController;
use Raven\Core\Controller\Panel\RedirectController;
use Raven\Core\Controller\Panel\SharedController;
use Raven\Core\Controller\Panel\SystemController;
use Raven\Core\Controller\Panel\TaxonomyController;
use Raven\Core\Controller\Panel\UserController;
use Raven\Core\Repository\CategoryRepository;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\GroupRepository;
use Raven\Core\Repository\InviteRepository;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\TagRepository;
use Raven\Core\Repository\SetRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Core\Logger;
use Raven\Core\Renderer;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\Panel\PanelInvitePolicyService;
use Raven\Lib\Auth\Panel\PanelPermissionDefinitionCatalog;
use Raven\Lib\Auth\Panel\PanelTwoFactorPreferencesService;
use Raven\Lib\Auth\PasswordChangePolicy;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Parser\FeedRouteParser;
use Raven\Lib\Parser\GroupDataParser;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Parser\RedirectDataParser;
use Raven\Lib\Parser\TaxonomyRepoParser;
use Raven\Lib\Media\Panel\PageImageManager;
use Raven\Lib\Media\Panel\TaxonomyImageService;
use Raven\Lib\Media\Panel\UserMediaPathService;
use Raven\Lib\Parser\UserDataParser;
use Raven\Lib\Scribe\TaxonomyImageScribe;
use Raven\Lib\Scribe\UserMediaScribe;
use Raven\Lib\View\Panel\Editor;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorMCE;
use Raven\Lib\View\Panel\EditorMDE;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\PanelMediaConfigService;
use Raven\Lib\Transport\Upload;
use RuntimeException;

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

        // Panel entry closures ($hasPanelPermissionBit, $canRenderPanelProfiler) capture
        // $rvn by value and call $rvn['auth']->method() directly, so auth must be a concrete
        // AuthService before build() returns — resolve both lazy DB and service handles now.
        if (is_callable($rvn['auth_db'])) {
            $rvn['auth_db'] = ($rvn['auth_db'])();
        }
        if (is_callable($rvn['auth'])) {
            $rvn['auth'] = ($rvn['auth'])();
        }

        $authController = null;
        $categoryController = null;
        $channelController = null;
        $configController = null;
        $contentController = null;
        $dashboardController = null;
        $groupController = null;
        $preferencesController = null;
        $panelSharedController = null;
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

        $rvn['view'] = new Renderer((string) $rvn['root'] . '/private/tpl');
        $categoryEnabled = ConfigParser::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = ConfigParser::bool($rvn['config']->get('tag.enabled', true), true);

        // Shared panel editor services — created once here and reused across every
        // controller factory so that extensions can also access panel_editor_tabs.
        $rvn['panel_editor_tabs'] = new EditorTabs($rvn['input']);
        $rvn['panel_editor'] = new Editor();
        $rvn['panel_editor_blocks'] = new EditorBlocks();
        // TinyMCE and EasyMDE helpers are registered here for extension access but
        // only injected into ContentController, which is the sole controller that
        // serves the rich page body editor.
        $rvn['panel_editor_mce'] = new EditorMCE();
        $rvn['panel_editor_mde'] = new EditorMDE();

        /**
         * Resolves the lazy auth DB handle only for panel factories that truly need it.
         */
        $resolveAuthDb = static function () use (&$rvn): PDO {
            $authDb = $rvn['auth_db'] ?? null;
            if (is_callable($authDb)) {
                $authDb = $authDb();
                $rvn['auth_db'] = $authDb;
            }

            if (!$authDb instanceof PDO) {
                throw new RuntimeException('Panel runtime auth database resolver is unavailable.');
            }

            return $authDb;
        };

        /**
         * Resolves the lazy auth service only for panel factories that truly need it.
         */
        $resolveAuth = static function () use (&$rvn): AuthService {
            $auth = $rvn['auth'] ?? null;
            if (is_callable($auth)) {
                $auth = $auth();
                $rvn['auth'] = $auth;
            }

            if (!$auth instanceof AuthService) {
                throw new RuntimeException('Panel runtime auth service resolver is unavailable.');
            }

            return $auth;
        };

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
        $userFactory = $memoize(static function () use (&$userRepository, $rvn, $resolveAuthDb): UserRepository {
            $userRepository = new UserRepository(
                $resolveAuthDb(),
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $userRepository;
        });

        /**
         * Builds the file-backed category set repository only for panel taxonomy editors.
         */
        $categorySetFactory = $memoize(static function () use (&$categorySetRepository, $rvn): SetRepository {
            $categorySetRepository = new SetRepository('category', (string) $rvn['root'] . '/private/dat/category-set');
            return $categorySetRepository;
        });

        /**
         * Builds the file-backed tag set repository only for panel taxonomy editors.
         */
        $tagSetFactory = $memoize(static function () use (&$tagSetRepository, $rvn): SetRepository {
            $tagSetRepository = new SetRepository('tag', (string) $rvn['root'] . '/private/dat/tag-set');
            return $tagSetRepository;
        });

        /**
         * Builds invite-token storage only for panel invite management.
         */
        $inviteTokenFactory = $memoize(static function () use (&$inviteTokenRepository, $rvn, $resolveAuthDb): InviteRepository {
            $inviteTokenRepository = new InviteRepository(
                $resolveAuthDb(),
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
        $loggerFactory = $memoize(static function () use (&$logger, $rvn): Logger {
            $logger = new Logger(
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
         * Builds taxonomy lookup parsing only for routing and page-editor flows
         * that need category/tag option lookups beyond channel routing.
         */
        $taxonomyLookupFactory = $memoize(static function () use (&$taxonomyLookupRepository, $rvn, $channelFactory): TaxonomyRepoParser {
            $taxonomyLookupRepository = new TaxonomyRepoParser(
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
        $rvn['panel_permission_map_provider'] = static function () use (&$rvn, $resolveAuth): array {
            if (($resolveAuth()->userId() ?? null) === null) {
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
        $rvn['panel_request_context'] = static function () use (&$panelSharedController, &$systemController, &$rvn, $categoryEnabled, $tagEnabled, $resolveAuth): SharedController {
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

            return $panelSharedController;
        };

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
         * Builds the split content controller on first use.
         * Owns page list, create/edit, save, gallery upload/delete, and page delete.
         */
        $rvn['panel_content_controller'] = static function () use (&$contentController, &$rvn, $panelContentDomain, $panelTaxonomyDomain): ContentController {
            if ($contentController instanceof ContentController) {
                return $contentController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $contentDomain = $panelContentDomain();
            $taxonomyDomain = $panelTaxonomyDomain();
            $contentController = new ContentController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                $contentDomain['page'],
                $contentDomain['page_images'],
                $contentDomain['page_image_manager'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['taxonomy_lookup'],
                $contentDomain['user'],
                new ChannelDataParser($rvn['config'], $rvn['input'], $contentDomain['channel']),
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                $rvn['panel_editor_blocks'],
                $rvn['panel_editor_mce'],
                $rvn['panel_editor_mde'],
                is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => []
            );

            return $contentController;
        };

        /**
         * Builds the split channel controller on first use.
         * Owns channel list, create/edit, save, and delete routes.
         */
        $rvn['panel_channel_controller'] = static function () use (&$channelController, &$rvn, $panelTaxonomyDomain): ChannelController {
            if ($channelController instanceof ChannelController) {
                return $channelController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $channelController = new ChannelController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['channel'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['category_enabled'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel']),
                new FeedRouteParser($rvn['config'], $rvn['input']),
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                new Upload()
            );

            return $channelController;
        };

        /**
         * Builds the split category controller on first use.
         * Owns category list, create/edit, save, delete, and category-set routes.
         */
        $rvn['panel_category_controller'] = static function () use (&$categoryController, &$rvn, $panelTaxonomyDomain): CategoryController {
            if ($categoryController instanceof CategoryController) {
                return $categoryController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $categoryController = new CategoryController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['category_enabled'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel']),
                $rvn['panel_editor_tabs'],
                new Upload()
            );

            return $categoryController;
        };

        /**
         * Builds the split redirect controller on first use.
         * Redirect CRUD only needs channel validation and redirect storage.
         */
        $rvn['panel_redirect_controller'] = static function () use (&$redirectController, &$rvn, $panelTaxonomyDomain): RedirectController {
            if ($redirectController instanceof RedirectController) {
                return $redirectController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $redirectController = new RedirectController(
                $requestContextFactory(),
                $rvn['input'],
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel']),
                $taxonomyDomain['redirect'],
                new RedirectDataParser($rvn['input'], $taxonomyDomain['redirect']),
                $rvn['panel_editor']
            );

            return $redirectController;
        };

        /**
         * Builds the split taxonomy controller on first use.
         * Owns tag, tag-set, and shared taxonomy deletion helpers.
         */
        $rvn['panel_taxonomy_controller'] = static function () use (&$taxonomyController, &$rvn, $panelTaxonomyDomain): TaxonomyController {
            if ($taxonomyController instanceof TaxonomyController) {
                return $taxonomyController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $taxonomyController = new TaxonomyController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel']),
                $rvn['panel_editor_tabs'],
                new Upload()
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

            /** @var callable(): SharedController $requestContextFactory */
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
                new GroupRouteParser($rvn['config'], $rvn['input']),
                new PanelInvitePolicyService($rvn['input']),
                new LoginIdentifierResolver(),
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                $rvn['panel_editor_blocks'],
                new PanelMediaConfigService($rvn['config']),
                new UserDataParser($rvn['input']),
                new PanelTwoFactorPreferencesService($rvn['input']),
                new UserMediaScribe((string) $rvn['root']),
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

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $groupDomain = $panelUserDomain();
            $groupController = new GroupController(
                $requestContextFactory(),
                $rvn['input'],
                $groupDomain['group'],
                new GroupDataParser($rvn['input'], $groupDomain['group']),
                new GroupRouteParser($rvn['config'], $rvn['input']),
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new PanelPermissionDefinitionCatalog(),
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

            return $groupController;
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
                new LoginIdentifierResolver(),
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                $rvn['panel_editor_blocks'],
                new PanelMediaConfigService($rvn['config']),
                new UserDataParser($rvn['input']),
                new PanelTwoFactorPreferencesService($rvn['input']),
                new UserMediaScribe((string) $rvn['root']),
                new UserMediaPathService(),
                new PasswordChangePolicy()
            );

            return $preferencesController;
        };

        /**
         * Builds the split configuration controller on first use.
         * Owns `/configuration` and `/configuration/save` only.
         */
        $rvn['panel_config_controller'] = static function () use (&$configController, &$rvn, $panelSystemDomain): ConfigController {
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
                (string) $rvn['root'],
                $systemDomain['channel'],
                $systemDomain['category_set'],
                $systemDomain['tag_set'],
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                $rvn['panel_editor_blocks']
            );

            return $configController;
        };

        /**
         * Builds the split system controller on first use.
         * Owns update, routing, logs, themes, and extensions.
         */
        $rvn['panel_system_controller'] = static function () use (&$systemController, &$rvn, $panelSystemDomain): SystemController {
            if ($systemController instanceof SystemController) {
                return $systemController;
            }

            /** @var callable(): SharedController $requestContextFactory */
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

            $contentDomain = $panelContentDomain();
            $taxonomyDomain = $panelTaxonomyDomain();
            $userDomain = $panelUserDomain();
            $panelSystemDomain();

            // Panel route files depend on this closure, so populate it only when panel runtime is active.
            $rvn['panel_site_data'] = static function (bool $includeDomain = true) use ($rvn, $categoryEnabled, $tagEnabled): array {
                $site = [
                    'name' => (string) $rvn['config']->get('site.name', 'Raven CMS'),
                    'panel_path' => (string) $rvn['config']->get('panel.path', 'panel'),
                    'panel_brand_name' => (string) $rvn['config']->get('panel.brand_name', ''),
                    'panel_brand_logo' => (string) $rvn['config']->get('panel.brand_logo', ''),
                    'category_enabled' => $categoryEnabled,
                    'tag_enabled' => $tagEnabled,
                ];

                if ($includeDomain) {
                    $site['domain'] = (string) $rvn['config']->get('site.domain', 'localhost');
                }

                return $site;
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
