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
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Log\EventLogger;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Repository\CategoryRepository;
use Raven\Repository\ChannelRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomyLookupRepository;
use Raven\Repository\TaxonomySetRepository;

/**
 * Enriches the shared core container with panel-runtime factories.
 *
 * @param array<string, mixed> $rvn Shared core bootstrap container.
 * @return array<string, mixed>
 */
return static function (array $rvn): array {
    /** @var mixed $service */
    $service = $rvn['service'] ?? null;
    if (
        !is_callable($service)
        || !isset($rvn['view'], $rvn['config'], $rvn['auth'], $rvn['input'], $rvn['csrf'])
    ) {
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

    /**
     * Builds the file-backed category set repository only for panel taxonomy editors.
     */
    $categorySetFactory = static function () use (&$categorySetRepository, $rvn): TaxonomySetRepository {
        if ($categorySetRepository instanceof TaxonomySetRepository) {
            return $categorySetRepository;
        }

        $categorySetRepository = new TaxonomySetRepository('category', (string) $rvn['root'] . '/private/dat/category-set');
        return $categorySetRepository;
    };

    /**
     * Builds the file-backed tag set repository only for panel taxonomy editors.
     */
    $tagSetFactory = static function () use (&$tagSetRepository, $rvn): TaxonomySetRepository {
        if ($tagSetRepository instanceof TaxonomySetRepository) {
            return $tagSetRepository;
        }

        $tagSetRepository = new TaxonomySetRepository('tag', (string) $rvn['root'] . '/private/dat/tag-set');
        return $tagSetRepository;
    };

    /**
     * Builds invite-token storage only for panel invite management.
     */
    $inviteTokenFactory = static function () use (&$inviteTokenRepository, $rvn): InviteTokenRepository {
        if ($inviteTokenRepository instanceof InviteTokenRepository) {
            return $inviteTokenRepository;
        }

        $inviteTokenRepository = new InviteTokenRepository(
            $rvn['auth_db'],
            (string) $rvn['driver'],
            (string) $rvn['prefix']
        );

        return $inviteTokenRepository;
    };

    /**
     * Builds the panel page-image helper only when page editing enters media flows.
     */
    $pageImageManagerFactory = static function () use (&$pageImageManager, $rvn, $service): PageImageManager {
        if ($pageImageManager instanceof PageImageManager) {
            return $pageImageManager;
        }

        /** @var \Raven\Repository\PageImageRepository $pageImages */
        $pageImages = $service('page_images');
        $pageImageManager = new PageImageManager($rvn['config'], $rvn['input'], $pageImages, (string) $rvn['root']);

        return $pageImageManager;
    };

    /**
     * Builds panel log storage only for routes that touch the event log UI.
     */
    $loggerFactory = static function () use (&$logger, $rvn): EventLogger {
        if ($logger instanceof EventLogger) {
            return $logger;
        }

        $logger = new EventLogger(
            $rvn['db'],
            (string) $rvn['driver'],
            (string) $rvn['prefix'],
            (array) $rvn['config']->get('logging', [])
        );

        return $logger;
    };

    /**
     * Builds category storage only for panel taxonomy flows that actually use categories.
     */
    $categoryFactory = static function () use (&$categoryRepository, $rvn): CategoryRepository {
        if ($categoryRepository instanceof CategoryRepository) {
            return $categoryRepository;
        }

        $categoryRepository = new CategoryRepository(
            $rvn['db'],
            (string) $rvn['driver'],
            (string) $rvn['prefix']
        );

        return $categoryRepository;
    };

    /**
     * Builds tag storage only for panel taxonomy flows that actually use tags.
     */
    $tagFactory = static function () use (&$tagRepository, $rvn): TagRepository {
        if ($tagRepository instanceof TagRepository) {
            return $tagRepository;
        }

        $tagRepository = new TagRepository(
            $rvn['db'],
            (string) $rvn['driver'],
            (string) $rvn['prefix']
        );

        return $tagRepository;
    };

    /**
     * Builds taxonomy lookup storage only for routing and page-editor flows
     * that need category/tag option lookups beyond channel routing.
     */
    $taxonomyLookupFactory = static function () use (&$taxonomyLookupRepository, $rvn, $service): TaxonomyLookupRepository {
        if ($taxonomyLookupRepository instanceof TaxonomyLookupRepository) {
            return $taxonomyLookupRepository;
        }

        /** @var ChannelRepository $channelRepository */
        $channelRepository = $service('channel');
        $taxonomyLookupRepository = new TaxonomyLookupRepository(
            $rvn['db'],
            (string) $rvn['driver'],
            (string) $rvn['prefix'],
            $channelRepository
        );

        return $taxonomyLookupRepository;
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
     * Builds panel-only repositories, extension services, and the full controller on demand.
     *
     * @return array<string, mixed>
     */
    $rvn['initialize_panel_runtime'] = static function () use (
        &$panelRuntime,
        &$panelController,
        &$rvn,
        $service,
        $categoryFactory,
        $categorySetFactory,
        $tagFactory,
        $tagSetFactory,
        $taxonomyLookupFactory,
        $inviteTokenFactory,
        $pageImageManagerFactory,
        $loggerFactory
    ): array {
        if (is_array($panelRuntime)) {
            return $rvn + $panelRuntime;
        }

        $rvn['channel'] = $service('channel');
        $rvn['group'] = $service('group');
        $rvn['page_images'] = $service('page_images');
        $rvn['page'] = $service('page');
        $rvn['redirect'] = $service('redirect');
        $rvn['user'] = $service('user');

        $categoryEnabled = ConfigValueParser::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = ConfigValueParser::bool($rvn['config']->get('tag.enabled', true), true);
        $siteContextBuilder = new SiteContextBuilder();

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
            $rvn['page_images'],
            $pageImageManagerFactory,
            $categoryFactory,
            $categorySetFactory,
            $rvn['channel'],
            $rvn['group'],
            $rvn['page'],
            $rvn['redirect'],
            $tagFactory,
            $tagSetFactory,
            $taxonomyLookupFactory,
            $rvn['user'],
            $inviteTokenFactory,
            $loggerFactory
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
