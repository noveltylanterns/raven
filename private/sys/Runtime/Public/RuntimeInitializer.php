<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Public/RuntimeInitializer.php
 * Public runtime initialization closure wiring.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Public;

use Closure;

/**
 * Registers public runtime initialization closures for entry orchestration.
 */
final class RuntimeInitializer
{
    /**
     * Registers the public runtime initialization closure.
     *
     * @param array<string, mixed> $rvn          Shared runtime container, mutated in-place.
     * @param Closure              $publicContentDomain Public content domain aggregate closure.
     * @param Closure              $publicAuthDomain    Public auth/user domain aggregate closure.
     * @param callable             $extensionServicesProvider Lazy extension services loader.
     * @param bool                 $categoryEnabled Whether category support is enabled for the request.
     * @param bool                 $tagEnabled      Whether tag support is enabled for the request.
     * @return void
     */
    public static function register(
        array &$rvn,
        Closure $publicContentDomain,
        Closure $publicAuthDomain,
        callable $extensionServicesProvider,
        bool $categoryEnabled,
        bool $tagEnabled
    ): void {
        $publicRuntime = null;

        /**
         * Warms domain aggregates, primes the extension services cache, and populates
         * public_site_data for the current request.
         *
         * @return array<string, mixed> Updated runtime container with public_site_data added.
         */
        $rvn['initialize_public_runtime'] = static function () use (
            &$publicRuntime,
            &$rvn,
            $publicContentDomain,
            $publicAuthDomain,
            $extensionServicesProvider,
            $categoryEnabled,
            $tagEnabled
        ): array {
            // Already initialized this request — return the cached merge immediately.
            if (is_array($publicRuntime)) {
                return $rvn + $publicRuntime;
            }

            // Warm domain aggregates so the first controller dispatch does not pay
            // the full lazy-build cost — matches the panel's init warm-up pattern.
            $contentDomain = $publicContentDomain();
            $authDomain    = $publicAuthDomain();

            // Prime the extension services cache once for the whole request so every
            // public controller that calls $extensionServicesProvider() gets the
            // already-resolved map rather than each triggering a fresh load.
            $extensionServicesProvider();

            // Populate public_site_data as a closure so callers can omit the domain
            // when they only need local display values — mirrors panel_site_data shape.
            $rvn['public_site_data'] = static function (bool $includeDomain = true) use ($rvn, $categoryEnabled, $tagEnabled): array {
                $site = [
                    'name'             => (string) $rvn['config']->get('site.name', 'Raven CMS'),
                    'category_enabled' => $categoryEnabled,
                    'tag_enabled'      => $tagEnabled,
                ];

                if ($includeDomain) {
                    $site['domain'] = (string) $rvn['config']->get('site.domain', 'localhost');
                }

                return $site;
            };

            $publicRuntime = [
                'category_enabled' => $categoryEnabled,
                'tag_enabled'      => $tagEnabled,
            ];

            // Release domain aggregate references after init — same hygiene as panel.
            unset($contentDomain, $authDomain);

            return $rvn + $publicRuntime;
        };
    }
}
