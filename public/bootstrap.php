<?php

/**
 * RAVEN CMS
 * ~/public/bootstrap.php
 * Public runtime bootstrap assembly for controller-level services.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Controller\PublicController;
use Raven\Repository\InviteTokenRepository;

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
    $inviteTokens = null;

    /**
     * Builds invite-token storage only for the registration flows that need it.
     */
    $inviteTokenRepository = static function () use (&$inviteTokens, $rvn): InviteTokenRepository {
        if ($inviteTokens instanceof InviteTokenRepository) {
            return $inviteTokens;
        }

        $inviteTokens = new InviteTokenRepository(
            $rvn['auth_db'],
            (string) $rvn['driver'],
            (string) $rvn['prefix']
        );

        return $inviteTokens;
    };

    /**
     * Boots extension providers only when public runtime code needs extension services.
     *
     * @return array<string, mixed>
     */
    $extensionServicesProvider = static function (?string $extensionDirectory = null) use (&$extensionServices, &$rvn): array {
        $extensionDirectory = is_string($extensionDirectory) ? trim($extensionDirectory) : '';
        if (
            $extensionDirectory !== ''
            && is_callable($rvn['extension_services_for'] ?? null)
        ) {
            /** @var callable(string): array<string, mixed> $extensionServicesFor */
            $extensionServicesFor = $rvn['extension_services_for'];
            return $extensionServicesFor($extensionDirectory);
        }

        if (is_array($extensionServices)) {
            return $extensionServices;
        }

        if (is_callable($rvn['extension_services_all'] ?? null)) {
            /** @var callable(): array<string, array<string, mixed>> $extensionServicesAll */
            $extensionServicesAll = $rvn['extension_services_all'];
            $extensionServices = $extensionServicesAll();
            return $extensionServices;
        }

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        $extensionServices = is_array($rawExtensionServices) ? $rawExtensionServices : [];
        return $extensionServices;
    };

    /**
     * Builds the public controller on first use so route registration stays light.
     */
    $rvn['public_controller'] = static function () use (&$publicController, $rvn, $service, $extensionServicesProvider, $inviteTokenRepository): PublicController {
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
            $inviteTokenRepository,
            $rvn['input'],
            $rvn['csrf'],
            $extensionServicesProvider
        );

        return $publicController;
    };

    $rvn['public_extension_services'] = $extensionServicesProvider;

    return $rvn;
};
