<?php

/**
 * RAVEN CMS
 * ~/public/bootstrap.php
 * Public runtime bootstrap assembly for controller-level services.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\PublicController;

/**
 * Enriches the shared core container with public-runtime factories.
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

    $publicController = null;
    $extensionServices = null;

    /**
     * Boots extension providers only when public runtime code needs extension services.
     *
     * @return array<string, mixed>
     */
    $extensionServicesProvider = static function () use (&$extensionServices, &$rvn): array {
        if (is_array($extensionServices)) {
            return $extensionServices;
        }

        if (is_callable($rvn['boot_extensions'] ?? null)) {
            /** @var callable(): array<string, mixed> $bootExtensions */
            $bootExtensions = $rvn['boot_extensions'];
            $rvn = $bootExtensions();
        }

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        $extensionServices = is_array($rawExtensionServices) ? $rawExtensionServices : [];
        return $extensionServices;
    };

    /**
     * Builds the public controller on first use so route registration stays light.
     */
    $rvn['public_controller'] = static function () use (&$publicController, $rvn, $service, $extensionServicesProvider): PublicController {
        if ($publicController instanceof PublicController) {
            return $publicController;
        }

        $publicController = new PublicController(
            $rvn['view'],
            $rvn['config'],
            $rvn['auth'],
            $service('group'),
            $service('page_images'),
            $service('page'),
            $service('redirect'),
            $service('taxonomy_lookup'),
            $service('user'),
            $service('invite_tokens'),
            $rvn['input'],
            $rvn['csrf'],
            $extensionServicesProvider
        );

        return $publicController;
    };

    $rvn['public_extension_services'] = $extensionServicesProvider;

    return $rvn;
};
