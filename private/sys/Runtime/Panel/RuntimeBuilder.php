<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Panel/RuntimeBuilder.php
 * Panel runtime assembly on top of the shared core bootstrap.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Panel;

use Closure;
use PDO;
use Raven\Core\Runtime\Panel\ControllerFactory;
use Raven\Core\Runtime\Panel\DomainFactory;
use Raven\Core\Runtime\Panel\RepoFactory;
use Raven\Core\Runtime\Panel\RuntimeInitializer;
use Raven\Core\Logger;
use Raven\Core\Renderer;
use Raven\Core\Gatekeeper;
use Raven\Lib\Extension\Panel\Content as ExtensionContent;
use Raven\Lib\Extension\Panel\Manager as ExtensionManager;
use Raven\Lib\Extension\Panel\Permissions as ExtensionPermissions;
use Raven\Lib\Extension\StateRead;
use Raven\Core\Repository\ConfigRead;
use Raven\Lib\Media\MediaUpload;
use Raven\Lib\View\Panel\EditorWrapper;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorMCE;
use Raven\Lib\View\Panel\EditorMDE;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Public\ThemeCatalog;
use RuntimeException;

/**
 * Builds panel-scope runtime factories on top of the shared Raven container.
 *
 * Keep this builder limited to wiring that is broadly needed across panel
 * requests. Route-family behavior should keep moving into panel routing
 * registrars and the split panel sub-controllers.
 */
final class RuntimeBuilder
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
        // Gatekeeper before build() returns — resolve both lazy DB and service handles now.
        if (is_callable($rvn['auth_db'])) {
            $rvn['auth_db'] = ($rvn['auth_db'])();
        }
        if (is_callable($rvn['auth'])) {
            $rvn['auth'] = ($rvn['auth'])();
        }

        $mediaManager = null;
        $logger = null;
        $extensionStateStore = null;
        $extensionPermissions = null;
        $extensionManager = null;
        $extensionContent = null;
        $themeCatalogService = null;

        $rvn['view'] = new Renderer((string) $rvn['root'] . '/private/tpl');
        $categoryEnabled = ConfigRead::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = ConfigRead::bool($rvn['config']->get('tag.enabled', true), true);


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
        $resolveAuth = static function () use (&$rvn): Gatekeeper {
            $auth = $rvn['auth'] ?? null;
            if (is_callable($auth)) {
                $auth = $auth();
                $rvn['auth'] = $auth;
            }

            if (!$auth instanceof Gatekeeper) {
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

        // EditorWrapper helpers are lazy factories so non-edit routes (dashboard, logs, config,
        // user list, etc.) pay zero construction cost. Each $memoize wrapper ensures the
        // object is created at most once per request regardless of how many edit routes
        // share the same controller instance.
        $rvn['panel_editor_tabs'] = $memoize(static function () use ($rvn): EditorTabs {
            return new EditorTabs($rvn['input']);
        });
        $rvn['panel_editor'] = $memoize(static fn (): EditorWrapper => new EditorWrapper());
        $rvn['panel_editor_blocks'] = $memoize(static fn (): EditorBlocks => new EditorBlocks());
        // MCE and MDE are page-editor-only; kept here so extensions accessing $rvn
        // can also pull them lazily if needed.
        $rvn['panel_editor_mce'] = $memoize(static fn (): EditorMCE => new EditorMCE());
        $rvn['panel_editor_mde'] = $memoize(static fn (): EditorMDE => new EditorMDE());

        /** @var array<string, Closure> $repoFactories */
        $repoFactories = RepoFactory::build($rvn, $memoize, $resolveAuthDb, $categoryEnabled, $tagEnabled);
        $channelReadFactory = $repoFactories['channel_read'];
        $channelWriteFactory = $repoFactories['channel_write'];
        $groupReadFactory = $repoFactories['group_read'];
        $groupWriteFactory = $repoFactories['group_write'];
        $mediaReadFactory = $repoFactories['media_read'];
        $mediaWriteFactory = $repoFactories['media_write'];
        $pageReadFactory = $repoFactories['page_read'];
        $pageWriteFactory = $repoFactories['page_write'];
        $redirectReadFactory = $repoFactories['redirect_read'];
        $redirectWriteFactory = $repoFactories['redirect_write'];
        $userReadFactory = $repoFactories['user_read'];
        $userWriteFactory = $repoFactories['user_write'];
        $categorySetFactory = $repoFactories['category_set'];
        $tagSetFactory = $repoFactories['tag_set'];
        $categorySetWriteFactory = $repoFactories['category_set_write'];
        $tagSetWriteFactory = $repoFactories['tag_set_write'];
        $inviteReadFactory = $repoFactories['invite_read'];
        $inviteWriteFactory = $repoFactories['invite_write'];
        $categoryReadFactory = $repoFactories['category_read'];
        $categoryWriteFactory = $repoFactories['category_write'];
        $tagReadFactory = $repoFactories['tag_read'];
        $tagWriteFactory = $repoFactories['tag_write'];
        $taxonomyLookupFactory = $repoFactories['taxonomy_lookup'];

        /**
         * Builds the panel media helper only when page editing enters media flows.
         *
         * The manager spans both gallery duplicate/order lookups and media-row mutations,
         * so it takes the split read/write seams directly.
         */
        $mediaManagerFactory = $memoize(static function () use (&$mediaManager, $rvn, $mediaReadFactory, $mediaWriteFactory): MediaUpload {
            $mediaManager = new MediaUpload(
                $rvn['config'],
                $rvn['input'],
                $mediaReadFactory(),
                $mediaWriteFactory(),
                (string) $rvn['root']
            );

            return $mediaManager;
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

        /** @var array<string, Closure> $domainFactories */
        $domainFactories = DomainFactory::build(
            $memoize,
            $channelReadFactory,
            $channelWriteFactory,
            $pageReadFactory,
            $pageWriteFactory,
            $mediaReadFactory,
            $mediaWriteFactory,
            $mediaManagerFactory,
            $userReadFactory,
            $userWriteFactory,
            $groupReadFactory,
            $groupWriteFactory,
            $inviteReadFactory,
            $inviteWriteFactory,
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
        );
        $panelContentDomain = $domainFactories['panel_domain_content'];
        $panelTaxonomyDomain = $domainFactories['panel_domain_taxonomy'];
        $panelUserDomain = $domainFactories['panel_domain_user'];
        $panelPreferencesDomain = $domainFactories['panel_domain_preferences'];
        $panelSystemDomain = $domainFactories['panel_domain_system'];

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
        $extensionStateStoreFactory = $memoize(static function () use (&$extensionStateStore, $rvn): StateRead {
            $extensionStateStore = new StateRead((string) $rvn['root'] . '/private/ext');

            return $extensionStateStore;
        });

        /**
         * Reuses one shared extension-permission catalog across panel bootstrap helpers.
         */
        $extensionPermissionsFactory = $memoize(static function () use (
            &$extensionPermissions,
            $rvn,
            $extensionStateStoreFactory
        ): ExtensionPermissions {
            $extensionPermissions = new ExtensionPermissions(
                $extensionStateStoreFactory(),
                $rvn['input']
            );

            return $extensionPermissions;
        });

        /**
         * Reuses one shared extension catalog for runtime-side manifest reads.
         */
        $extensionManagerFactory = $memoize(static function () use (
            &$extensionManager,
            $rvn,
            $extensionStateStoreFactory,
            $extensionPermissionsFactory
        ): ExtensionManager {
            $extensionManager = new ExtensionManager(
                (string) $rvn['root'],
                $extensionStateStoreFactory(),
                $extensionPermissionsFactory(),
                $rvn['config'],
                $rvn['input']
            );

            return $extensionManager;
        });

        /**
         * Reuses one shared extension content catalog for page-editor block and shortcode reads.
         */
        $extensionContentFactory = $memoize(static function () use (
            &$extensionContent,
            $rvn
        ): ExtensionContent {
            $extensionContent = new ExtensionContent(
                (string) $rvn['root'],
                $rvn['input'],
                new \Raven\Lib\Parser\PageBlockParser($rvn['input'])
            );

            return $extensionContent;
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

        ControllerFactory::registerBase(
            $rvn,
            $resolveAuth,
            $extensionManagerFactory,
            $extensionFormsProvider
        );

        ControllerFactory::registerContentTaxonomyControllers(
            $rvn,
            $panelContentDomain,
            $panelTaxonomyDomain,
            $extensionStateStoreFactory,
            $extensionManagerFactory,
            $extensionContentFactory,
            $categoryEnabled,
            $tagEnabled
        );

        ControllerFactory::registerUserAdminControllers(
            $rvn,
            $panelUserDomain,
            $panelSystemDomain,
            $loggerFactory,
            $themeCatalogFactory,
            $extensionStateStoreFactory,
            $extensionManagerFactory
        );

        RuntimeInitializer::register(
            $rvn,
            $panelContentDomain,
            $panelTaxonomyDomain,
            $panelUserDomain,
            $panelSystemDomain,
            $categoryEnabled,
            $tagEnabled
        );

        return $rvn;
    }
}
