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
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Site\SiteContextBuilder;

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
    $rvn['initialize_panel_runtime'] = static function () use (&$panelRuntime, &$panelController, &$rvn, $service): array {
        if (is_array($panelRuntime)) {
            return $rvn + $panelRuntime;
        }

        $rvn['category'] = $service('category');
        $rvn['category_set'] = $service('category_set');
        $rvn['channel'] = $service('channel');
        $rvn['group'] = $service('group');
        $rvn['invite_tokens'] = $service('invite_tokens');
        $rvn['page_images'] = $service('page_images');
        $rvn['page_image_manager'] = $service('page_image_manager');
        $rvn['page'] = $service('page');
        $rvn['redirect'] = $service('redirect');
        $rvn['tag'] = $service('tag');
        $rvn['tag_set'] = $service('tag_set');
        $rvn['taxonomy_lookup'] = $service('taxonomy_lookup');
        $rvn['user'] = $service('user');
        $rvn['logger'] = $service('logger');

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
            $rvn['page_image_manager'],
            $rvn['category'],
            $rvn['category_set'],
            $rvn['channel'],
            $rvn['group'],
            $rvn['page'],
            $rvn['redirect'],
            $rvn['tag'],
            $rvn['tag_set'],
            $rvn['taxonomy_lookup'],
            $rvn['user'],
            $rvn['invite_tokens'],
            $rvn['logger']
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
