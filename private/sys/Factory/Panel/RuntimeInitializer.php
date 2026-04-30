<?php

/**
 * RAVEN CMS
 * ~/private/sys/Factory/Panel/RuntimeInitializer.php
 * Panel runtime initialization closure wiring extracted from panel runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Factory\Panel;

use Closure;

/**
 * Registers panel runtime initialization closures for entry orchestration.
 */
final class RuntimeInitializer
{
    /**
     * Registers the panel runtime initialization closure.
     *
     * @param array<string, mixed> $rvn Shared runtime container, mutated in-place.
     * @param Closure $panelContentDomain Panel content domain aggregate closure.
     * @param Closure $panelTaxonomyDomain Panel taxonomy domain aggregate closure.
     * @param Closure $panelUserDomain Panel user/group domain aggregate closure.
     * @param Closure $panelSystemDomain Panel system/routing domain aggregate closure.
     * @param bool $categoryEnabled Whether category support is enabled for the current request.
     * @param bool $tagEnabled Whether tag support is enabled for the current request.
     * @return void
     */
    public static function register(
        array &$rvn,
        Closure $panelContentDomain,
        Closure $panelTaxonomyDomain,
        Closure $panelUserDomain,
        Closure $panelSystemDomain,
        bool $categoryEnabled,
        bool $tagEnabled
    ): void {
        $panelRuntime = null;

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

            // Touch lazy domains during panel runtime initialization to preserve legacy side effects.
            unset($contentDomain, $taxonomyDomain, $userDomain);

            return $rvn + $panelRuntime;
        };
    }
}
