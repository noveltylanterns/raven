<?php

/**
 * RAVEN CMS
 * ~/panel/bootstrap.php
 * Panel runtime bootstrap assembly for auth and dashboard services.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\AuthController;
use Raven\Controller\PanelController;
use Raven\Core\Media\PageImageManager;
use Raven\Core\View;
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Log\EventLogger;
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
 * Enriches the shared core container with panel-runtime factories.
 *
 * @param array<string, mixed> $rvn Shared core bootstrap container.
 * @return array<string, mixed>
 */
return static function (array $rvn): array {
    if (!isset($rvn['root'], $rvn['db'], $rvn['auth_db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], $rvn['auth'], $rvn['input'], $rvn['csrf'])) {
        return $rvn;
    }

    $authController = null;
    $panelController = null;
    $panelRuntime = null;
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
    $categoryEnabled = ConfigValueParser::bool($rvn['config']->get('category.enabled', true), true);
    $tagEnabled = ConfigValueParser::bool($rvn['config']->get('tag.enabled', true), true);

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
     * Content routes share page/channel/media dependencies, which will map
     * directly to the future panel content sub-controller.
     *
     * @return array<string, mixed>
     */
    $panelContentDomain = $memoize(static function () use (
        $channelFactory,
        $pageFactory,
        $pageImagesFactory,
        $pageImageManagerFactory
    ): array {
        return [
            'channel' => $channelFactory(),
            'page' => $pageFactory(),
            'page_images' => $pageImagesFactory(),
            'page_image_manager' => $pageImageManagerFactory,
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
        $pageFactory,
        $redirectFactory,
        $userFactory,
        $loggerFactory,
        $taxonomyLookupFactory,
        $categoryEnabled,
        $tagEnabled
    ): array {
        return [
            'channel' => $channelFactory(),
            'page' => $pageFactory(),
            'redirect' => $redirectFactory(),
            'user' => $userFactory(),
            'logger' => $loggerFactory,
            'taxonomy_lookup' => $taxonomyLookupFactory,
            'category_enabled' => $categoryEnabled,
            'tag_enabled' => $tagEnabled,
        ];
    });

    $rvn['panel_domain_content'] = $panelContentDomain;
    $rvn['panel_domain_taxonomy'] = $panelTaxonomyDomain;
    $rvn['panel_domain_user'] = $panelUserDomain;
    $rvn['panel_domain_group'] = $panelUserDomain;
    $rvn['panel_domain_preferences'] = $panelPreferencesDomain;
    $rvn['panel_domain_system'] = $panelSystemDomain;

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
     * Builds panel-only repositories, extension services, and the full controller on demand.
     *
     * @return array<string, mixed>
     */
    $rvn['initialize_panel_runtime'] = static function () use (
        &$panelRuntime,
        &$panelController,
        &$rvn,
        $panelContentDomain,
        $panelTaxonomyDomain,
        $panelUserDomain,
        $panelSystemDomain
    ): array {
        if (is_array($panelRuntime)) {
            return $rvn + $panelRuntime;
        }

        $siteContextBuilder = new SiteContextBuilder();
        $contentDomain = $panelContentDomain();
        $taxonomyDomain = $panelTaxonomyDomain();
        $userDomain = $panelUserDomain();
        $systemDomain = $panelSystemDomain();

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

        $panelController = new PanelController(
            $rvn['view'],
            $rvn['config'],
            $rvn['auth'],
            $rvn['input'],
            $rvn['csrf'],
            $contentDomain['page_images'],
            $contentDomain['page_image_manager'],
            $taxonomyDomain['category'],
            $taxonomyDomain['category_set'],
            $contentDomain['channel'],
            $userDomain['group'],
            $contentDomain['page'],
            $taxonomyDomain['redirect'],
            $taxonomyDomain['tag'],
            $taxonomyDomain['tag_set'],
            $taxonomyDomain['taxonomy_lookup'],
            $userDomain['user'],
            $userDomain['invite_tokens'],
            $systemDomain['logger']
        );

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

    /**
     * Builds the heavy panel controller only after a route actually needs it.
     */
    $rvn['panel_controller'] = static function () use (&$panelController, $rvn): PanelController {
        if ($panelController instanceof PanelController) {
            return $panelController;
        }

        /** @var callable(): array<string, mixed> $initializePanelRuntime */
        $initializePanelRuntime = $rvn['initialize_panel_runtime'];
        $initializePanelRuntime();

        return $panelController;
    };

    return $rvn;
};
