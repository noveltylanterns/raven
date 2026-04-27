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
use Raven\Core\Controller\Panel\DashboardController;
use Raven\Core\Controller\Panel\GroupController;
use Raven\Core\Controller\Panel\LogsController;
use Raven\Core\Controller\Panel\PageController;
use Raven\Core\Controller\Panel\PreferencesController;
use Raven\Core\Controller\Panel\RedirectController;
use Raven\Core\Controller\Panel\RoutingController;
use Raven\Core\Controller\Panel\SharedController;
use Raven\Core\Controller\Panel\SystemController;
use Raven\Core\Controller\Panel\TagController;
use Raven\Core\Controller\Panel\UpdateController;
use Raven\Core\Controller\Panel\UserController;
use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\CategoryWrite;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\GroupWrite;
use Raven\Core\Repository\InviteRead;
use Raven\Core\Repository\InviteWrite;
use Raven\Core\Repository\PageImageRead;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageImageWrite;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\PageWrite;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\RedirectWrite;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\SetWrite;
use Raven\Core\Repository\TagRead;
use Raven\Core\Repository\TagWrite;
use Raven\Core\Repository\UserRead;
use Raven\Core\Repository\UserWrite;
use Raven\Core\Logger;
use Raven\Core\Renderer;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\Panel\PanelInvitePolicyService;
use Raven\Lib\Auth\Panel\PanelPermissionDefinitionCatalog;
use Raven\Lib\Auth\Panel\PanelTwoFactorPreferencesService;
use Raven\Lib\Auth\PasswordChangePolicy;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Extension\ExtensionStateStore;
use Raven\Lib\Extension\Panel\ExtensionCatalogService;
use Raven\Lib\Extension\Panel\ExtensionPermissionCatalogService;
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
use Raven\Lib\View\Error as ViewError;
use Raven\Lib\View\Public\ThemeCatalog;
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
        $pageController = null;
        $dashboardController = null;
        $groupController = null;
        $preferencesController = null;
        $panelSharedController = null;
        $panelRuntime = null;
        $redirectController = null;
        $logsController = null;
        $routingController = null;
        $systemController = null;
        $tagController = null;
        $updateController = null;
        $userController = null;
        $categoryRead = null;
        $categoryWrite = null;
        $categorySetRead = null;
        $categorySetWrite = null;
        $tagRead = null;
        $tagWrite = null;
        $tagSetRead = null;
        $tagSetWrite = null;
        $inviteRead = null;
        $inviteWrite = null;
        $pageImageRead = null;
        $pageImageWrite = null;
        $pageImageManager = null;
        $logger = null;
        $taxonomyLookupRepository = null;
        $channelRead = null;
        $channelWrite = null;
        $extensionStateStore = null;
        $extensionPermissionCatalogService = null;
        $extensionCatalogService = null;
        $extensionEditorCatalogService = null;
        $themeCatalogService = null;
        $groupRead = null;
        $groupWrite = null;
        $pageRead = null;
        $pageWrite = null;
        $redirectRead = null;
        $redirectWrite = null;
        $userRead = null;
        $userWrite = null;

        $rvn['view'] = new Renderer((string) $rvn['root'] . '/private/tpl');
        $categoryEnabled = ConfigParser::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = ConfigParser::bool($rvn['config']->get('tag.enabled', true), true);

        // Shared panel editor services — created once here and reused across every
        // controller factory so that extensions can also access panel_editor_tabs.
        $rvn['panel_editor_tabs'] = new EditorTabs($rvn['input']);
        $rvn['panel_editor'] = new Editor();
        $rvn['panel_editor_blocks'] = new EditorBlocks();
        // TinyMCE and EasyMDE helpers are registered here for extension access but
        // only injected into PageController, which is the sole controller that
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
         * Builds channel read side for panel content and routing flows.
         */
        $channelReadFactory = $memoize(static function () use (&$channelRead, $rvn): ChannelRead {
            $channelRead = new ChannelRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                (string) $rvn['root'] . '/private/dat/channel'
            );

            return $channelRead;
        });

        /**
         * Builds channel write side for panel channel-save and delete routes.
         */
        $channelWriteFactory = $memoize(static function () use (&$channelWrite, $rvn, $channelReadFactory): ChannelWrite {
            $channelWrite = new ChannelWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory(),
                (string) $rvn['root'] . '/private/dat/channel'
            );

            return $channelWrite;
        });

        /**
         * Builds group read side for panel group-listing flows.
         */
        $groupReadFactory = $memoize(static function () use (&$groupRead, $rvn): GroupRead {
            $groupRead = new GroupRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $groupRead;
        });

        /**
         * Builds group write side for panel group-save and delete routes.
         */
        $groupWriteFactory = $memoize(static function () use (&$groupWrite, $rvn, $groupReadFactory): GroupWrite {
            $groupWrite = new GroupWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $groupReadFactory()
            );

            return $groupWrite;
        });

        /**
         * Builds page-image read side for panel gallery renders and existence checks.
         */
        $pageImagesReadFactory = $memoize(static function () use (&$pageImageRead, $rvn): PageImageRead {
            $pageImageRead = new PageImageRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $pageImageRead;
        });

        /**
         * Builds page-image write side for panel gallery persistence.
         */
        $pageImagesWriteFactory = $memoize(static function () use (&$pageImageWrite, $rvn): PageImageWrite {
            $pageImageWrite = new PageImageWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $pageImageWrite;
        });

        /**
         * Builds page read side for panel content/routing flows.
         */
        $pageReadFactory = $memoize(static function () use (&$pageRead, $rvn, $channelReadFactory, $categoryEnabled, $tagEnabled): PageRead {
            $pageRead = new PageRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory(),
                $categoryEnabled,
                $tagEnabled
            );

            return $pageRead;
        });

        /**
         * Builds page write side for panel page-save and delete routes.
         */
        $pageWriteFactory = $memoize(static function () use (&$pageWrite, $rvn, $pageReadFactory, $channelReadFactory, $categoryEnabled, $tagEnabled): PageWrite {
            $pageWrite = new PageWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory(),
                $categoryEnabled,
                $tagEnabled
            );

            return $pageWrite;
        });

        /**
         * Builds redirect read side for panel routing inventory flows.
         */
        $redirectReadFactory = $memoize(static function () use (&$redirectRead, $rvn, $channelReadFactory): RedirectRead {
            $redirectRead = new RedirectRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
            );

            return $redirectRead;
        });

        /**
         * Builds redirect write side for panel redirect-save and delete routes.
         */
        $redirectWriteFactory = $memoize(static function () use (&$redirectWrite, $rvn, $channelReadFactory): RedirectWrite {
            $redirectWrite = new RedirectWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
            );

            return $redirectWrite;
        });

        /**
         * Builds user read side for panel user listings and parser seams.
         */
        $userReadFactory = $memoize(static function () use (&$userRead, $rvn, $resolveAuthDb): UserRead {
            $userRead = new UserRead(
                $resolveAuthDb(),
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $userRead;
        });

        /**
         * Builds user write side for panel user-save and delete routes.
         */
        $userWriteFactory = $memoize(static function () use (&$userWrite, $rvn, $resolveAuthDb): UserWrite {
            $userWrite = new UserWrite(
                $resolveAuthDb(),
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $userWrite;
        });

        /**
         * Builds the file-backed category set read side only for panel taxonomy editors.
         */
        $categorySetFactory = $memoize(static function () use (&$categorySetRead, $rvn): SetRead {
            $categorySetRead = new SetRead('category', (string) $rvn['root'] . '/private/dat/category-set');
            return $categorySetRead;
        });

        /**
         * Builds the file-backed tag set read side only for panel taxonomy editors.
         */
        $tagSetFactory = $memoize(static function () use (&$tagSetRead, $rvn): SetRead {
            $tagSetRead = new SetRead('tag', (string) $rvn['root'] . '/private/dat/tag-set');
            return $tagSetRead;
        });

        /**
         * Builds the file-backed category set write side only for panel category-set save and delete routes.
         */
        $categorySetWriteFactory = $memoize(static function () use (&$categorySetWrite, $rvn, $categorySetFactory): SetWrite {
            $categorySetWrite = new SetWrite('category', (string) $rvn['root'] . '/private/dat/category-set', $categorySetFactory());
            return $categorySetWrite;
        });

        /**
         * Builds the file-backed tag set write side only for panel tag-set save and delete routes.
         */
        $tagSetWriteFactory = $memoize(static function () use (&$tagSetWrite, $rvn, $tagSetFactory): SetWrite {
            $tagSetWrite = new SetWrite('tag', (string) $rvn['root'] . '/private/dat/tag-set', $tagSetFactory());
            return $tagSetWrite;
        });

        /**
         * Builds invite read side only for panel invite listing.
         */
        $inviteReadFactory = $memoize(static function () use (&$inviteRead, $rvn, $resolveAuthDb): InviteRead {
            $inviteRead = new InviteRead(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $inviteRead;
        });

        /**
         * Builds invite write side only for panel invite creation/deletion.
         */
        $inviteWriteFactory = $memoize(static function () use (&$inviteWrite, $rvn, $resolveAuthDb, $inviteReadFactory): InviteWrite {
            $inviteWrite = new InviteWrite(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $inviteReadFactory()
            );

            return $inviteWrite;
        });

        /**
         * Builds the panel page-image helper only when page editing enters media flows.
         *
         * PageImageManager is a justified holdout: it needs both hasHashForPage/nextSortOrderForPage
         * (read-side) and insertImageWithVariants/deleteImageForPage (write-side) in one object.
         * Keep using PageImageRepository bridge until PageImageManager is refactored to accept
         * separate read+write injections.
         */
        $pageImageManagerFactory = $memoize(static function () use (&$pageImageManager, $rvn): PageImageManager {
            $bridge = new PageImageRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
            $pageImageManager = new PageImageManager($rvn['config'], $rvn['input'], $bridge, (string) $rvn['root']);

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
         * Builds category read side only for panel taxonomy flows that actually use categories.
         */
        $categoryReadFactory = $memoize(static function () use (&$categoryRead, $rvn): CategoryRead {
            $categoryRead = new CategoryRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $categoryRead;
        });

        /**
         * Builds category write side only for panel category-save and delete routes.
         */
        $categoryWriteFactory = $memoize(static function () use (&$categoryWrite, $rvn, $categoryReadFactory): CategoryWrite {
            $categoryWrite = new CategoryWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $categoryReadFactory()
            );

            return $categoryWrite;
        });

        /**
         * Builds tag read side only for panel taxonomy flows that actually use tags.
         */
        $tagReadFactory = $memoize(static function () use (&$tagRead, $rvn): TagRead {
            $tagRead = new TagRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $tagRead;
        });

        /**
         * Builds tag write side only for panel tag-save and delete routes.
         */
        $tagWriteFactory = $memoize(static function () use (&$tagWrite, $rvn, $tagReadFactory): TagWrite {
            $tagWrite = new TagWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $tagReadFactory()
            );

            return $tagWrite;
        });

        /**
         * Builds taxonomy lookup parsing only for routing and page-editor flows
         * that need category/tag option lookups beyond channel routing.
         */
        $taxonomyLookupFactory = $memoize(static function () use (&$taxonomyLookupRepository, $rvn, $channelReadFactory): TaxonomyRepoParser {
            $taxonomyLookupRepository = new TaxonomyRepoParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
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
            $channelReadFactory,
            $pageReadFactory,
            $pageWriteFactory,
            $pageImagesReadFactory,
            $pageImagesWriteFactory,
            $pageImageManagerFactory,
            $userReadFactory
        ): array {
            return [
                'channel_read' => $channelReadFactory(),
                'page_read' => $pageReadFactory(),
                'page_write' => $pageWriteFactory(),
                'page_images_read' => $pageImagesReadFactory(),
                'page_images_write' => $pageImagesWriteFactory(),
                'page_image_manager' => $pageImageManagerFactory,
                'user_read' => $userReadFactory(),
            ];
        });

        /**
         * Taxonomy routes share channel/routing deps plus lazy category/tag
         * resolvers, matching the future taxonomy sub-controller seam.
         *
         * @return array<string, mixed>
         */
        $panelTaxonomyDomain = $memoize(static function () use (
            $channelReadFactory,
            $channelWriteFactory,
            $redirectReadFactory,
            $redirectWriteFactory,
            $categoryReadFactory,
            $categoryWriteFactory,
            $categorySetFactory,
            $categorySetWriteFactory,
            $tagReadFactory,
            $tagWriteFactory,
            $tagSetFactory,
            $tagSetWriteFactory,
            $taxonomyLookupFactory,
            $categoryEnabled,
            $tagEnabled
        ): array {
            return [
                'channel_read' => $channelReadFactory(),
                'channel_write' => $channelWriteFactory(),
                'redirect_read' => $redirectReadFactory(),
                'redirect_write' => $redirectWriteFactory(),
                'category' => $categoryReadFactory,
                'category_write' => $categoryWriteFactory,
                'category_set' => $categorySetFactory,
                'category_set_write' => $categorySetWriteFactory,
                'tag' => $tagReadFactory,
                'tag_write' => $tagWriteFactory,
                'tag_set' => $tagSetFactory,
                'tag_set_write' => $tagSetWriteFactory,
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
        $panelUserDomain = $memoize(static function () use (
            $groupReadFactory,
            $groupWriteFactory,
            $userReadFactory,
            $userWriteFactory,
            $inviteReadFactory,
            $inviteWriteFactory
        ): array {
            return [
                'group_read' => $groupReadFactory(),
                'group_write' => $groupWriteFactory(),
                'user_read' => $userReadFactory(),
                'user_write' => $userWriteFactory(),
                'invite_read' => $inviteReadFactory,
                'invite_write' => $inviteWriteFactory,
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
            $channelReadFactory,
            $categorySetFactory,
            $pageReadFactory,
            $redirectReadFactory,
            $tagSetFactory,
            $taxonomyLookupFactory,
            $userReadFactory,
            $loggerFactory
        ): array {
            return [
                'channel' => $channelReadFactory(),
                'category_set' => $categorySetFactory,
                'page' => $pageReadFactory(),
                'redirect' => $redirectReadFactory(),
                'tag_set' => $tagSetFactory,
                'taxonomy_lookup' => $taxonomyLookupFactory,
                'user' => $userReadFactory(),
            ];
        });

        $rvn['panel_domain_content'] = $panelContentDomain;
        $rvn['panel_domain_taxonomy'] = $panelTaxonomyDomain;
        $rvn['panel_domain_user'] = $panelUserDomain;
        $rvn['panel_domain_group'] = $panelUserDomain;
        $rvn['panel_domain_preferences'] = $panelPreferencesDomain;
        $rvn['panel_domain_logs'] = $loggerFactory;
        $rvn['panel_domain_system'] = $panelSystemDomain;

        /**
         * Reads enabled form rows from one extension service map.
         *
         * Extension manifest validation needs the same shortcode/form context on
         * panel bootstrap that the system controller already uses for extension
         * management screens. Keep the lookup local to the runtime builder so the
         * permission-map seam stays library-owned instead of routing through a controller.
         *
         * @param string $extensionKey Extension directory key.
         * @return array<int, array{name: string, slug: string}>
         */
        $extensionFormsProvider = static function (string $extensionKey) use (&$rvn): array {
            $normalized = strtolower(trim($extensionKey));
            $extensionServicesFor = $rvn['extension_services_for'] ?? null;
            if (!is_callable($extensionServicesFor)) {
                return [];
            }

            $extensionServices = $extensionServicesFor($normalized);
            if (!is_array($extensionServices)) {
                return [];
            }

            $formsRepository = $extensionServices['forms'] ?? null;
            if (!is_object($formsRepository) || !method_exists($formsRepository, 'listAll')) {
                return [];
            }

            /** @var mixed $rows */
            $rows = $formsRepository->listAll();
            if (!is_array($rows)) {
                return [];
            }

            $items = [];
            foreach ($rows as $row) {
                if (!is_array($row) || empty($row['enabled'])) {
                    continue;
                }

                $slug = strtolower(trim((string) ($row['slug'] ?? '')));
                if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $name = $slug;
                }

                $items[] = [
                    'name' => $name,
                    'slug' => $slug,
                ];
            }

            return $items;
        };

        /**
         * Reuses one shared extension-state store across panel bootstrap helpers.
         */
        $extensionStateStoreFactory = $memoize(static function () use (&$extensionStateStore, $rvn): ExtensionStateStore {
            $extensionStateStore = new ExtensionStateStore((string) $rvn['root'] . '/private/ext');

            return $extensionStateStore;
        });

        /**
         * Reuses one shared extension-permission catalog across panel bootstrap helpers.
         */
        $extensionPermissionCatalogFactory = $memoize(static function () use (
            &$extensionPermissionCatalogService,
            $rvn,
            $extensionStateStoreFactory
        ): ExtensionPermissionCatalogService {
            $extensionPermissionCatalogService = new ExtensionPermissionCatalogService(
                $extensionStateStoreFactory(),
                $rvn['input']
            );

            return $extensionPermissionCatalogService;
        });

        /**
         * Reuses one shared extension catalog for runtime-side manifest reads.
         */
        $extensionCatalogFactory = $memoize(static function () use (
            &$extensionCatalogService,
            $rvn,
            $extensionStateStoreFactory,
            $extensionPermissionCatalogFactory
        ): ExtensionCatalogService {
            $extensionCatalogService = new ExtensionCatalogService(
                (string) $rvn['root'],
                $extensionStateStoreFactory(),
                $extensionPermissionCatalogFactory(),
                $rvn['config'],
                $rvn['input']
            );

            return $extensionCatalogService;
        });

        /**
         * Reuses one shared extension editor catalog for page-editor contribution reads.
         */
        $extensionEditorCatalogFactory = $memoize(static function () use (
            &$extensionEditorCatalogService,
            $rvn
        ): ExtensionEditorCatalogService {
            $extensionEditorCatalogService = new ExtensionEditorCatalogService(
                (string) $rvn['root'],
                $rvn['input'],
                new \Raven\Lib\Parser\PageBlockParser($rvn['input'])
            );

            return $extensionEditorCatalogService;
        });

        /**
         * Reuses one shared public-theme catalog for runtime-side stock-theme reads.
         */
        $themeCatalogFactory = $memoize(static function () use (&$themeCatalogService, $rvn): ThemeCatalog {
            $themeCatalogService = new ThemeCatalog(
                (string) $rvn['root'] . '/public/theme',
                $rvn['input'],
                ['raven']
            );

            return $themeCatalogService;
        });

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
            $extensionCatalogFactory,
            $extensionFormsProvider
        ): array {
            if (($resolveAuth()->userId() ?? null) === null) {
                return [];
            }

            $extensionCatalog = $extensionCatalogFactory();
            return $extensionCatalog->panelPermissionMapForDirectories(
                $directoryFilter,
                static fn (string $extensionPath): array => $extensionCatalog->readManifest($extensionPath, $extensionFormsProvider)
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
         * Builds the split page controller on first use.
         * Owns page list, create/edit, save, gallery upload/delete, and page delete.
         */
        $rvn['panel_page_controller'] = static function () use (
            &$pageController,
            &$rvn,
            $panelContentDomain,
            $panelTaxonomyDomain,
            $extensionStateStoreFactory,
            $extensionCatalogFactory,
            $extensionEditorCatalogFactory
        ): PageController {
            if ($pageController instanceof PageController) {
                return $pageController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $contentDomain = $panelContentDomain();
            $taxonomyDomain = $panelTaxonomyDomain();
            $pageController = new PageController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                $contentDomain['page_read'],
                $contentDomain['page_write'],
                $contentDomain['page_images_read'],
                $contentDomain['page_images_write'],
                $contentDomain['page_image_manager'],
                $taxonomyDomain['category'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['taxonomy_lookup'],
                $contentDomain['user_read'],
                new ChannelDataParser($rvn['config'], $rvn['input'], $contentDomain['channel_read']),
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                $rvn['panel_editor_blocks'],
                $rvn['panel_editor_mce'],
                $rvn['panel_editor_mde'],
                $extensionStateStoreFactory(),
                $extensionCatalogFactory(),
                $extensionEditorCatalogFactory(),
                is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => []
            );

            return $pageController;
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
                $taxonomyDomain['channel_write'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['category_enabled'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel_read']),
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
                $taxonomyDomain['category_write'],
                $taxonomyDomain['category_set'],
                $taxonomyDomain['category_set_write'],
                $taxonomyDomain['category_enabled'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel_read']),
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
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel_read']),
                $taxonomyDomain['redirect_write'],
                new RedirectDataParser($rvn['input'], $taxonomyDomain['redirect_read']),
                $rvn['panel_editor']
            );

            return $redirectController;
        };

        /**
         * Builds the split tag controller on first use.
         * Owns tag list, create/edit, save, delete, and tag-set routes.
         */
        $rvn['panel_tag_controller'] = static function () use (&$tagController, &$rvn, $panelTaxonomyDomain): TagController {
            if ($tagController instanceof TagController) {
                return $tagController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $taxonomyDomain = $panelTaxonomyDomain();
            $tagController = new TagController(
                $requestContextFactory(),
                $rvn['input'],
                $taxonomyDomain['tag'],
                $taxonomyDomain['tag_write'],
                $taxonomyDomain['tag_set'],
                $taxonomyDomain['tag_set_write'],
                $taxonomyDomain['tag_enabled'],
                new TaxonomyImageService($rvn['config']),
                new TaxonomyImageScribe($rvn['config'], (string) $rvn['root']),
                new ChannelDataParser($rvn['config'], $rvn['input'], $taxonomyDomain['channel_read']),
                $rvn['panel_editor_tabs'],
                new Upload()
            );

            return $tagController;
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
                new GroupDataParser($rvn['input'], $userDomain['group_read']),
                $userDomain['user_read'],
                $userDomain['user_write'],
                $userDomain['invite_read'],
                $userDomain['invite_write'],
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
                $groupDomain['group_write'],
                new GroupDataParser($rvn['input'], $groupDomain['group_read']),
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
            $extensionCatalogFactory,
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
                $extensionCatalogFactory()->stockExtensionDirectories()
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
                $rvn['panel_editor_tabs'],
                $rvn['panel_editor'],
                $rvn['panel_editor_blocks'],
                $themeCatalogFactory()
            );

            return $configController;
        };

        /**
         * Builds the split system controller on first use.
         * Owns themes and extensions.
         */
        $rvn['panel_system_controller'] = static function () use (
            &$systemController,
            &$rvn,
            $extensionStateStoreFactory,
            $extensionCatalogFactory,
            $themeCatalogFactory
        ): SystemController {
            if ($systemController instanceof SystemController) {
                return $systemController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['panel_request_context'];
            $systemController = new SystemController(
                $requestContextFactory(),
                $rvn['config'],
                $rvn['input'],
                (string) $rvn['root'],
                $extensionStateStoreFactory(),
                $extensionCatalogFactory(),
                $themeCatalogFactory(),
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
