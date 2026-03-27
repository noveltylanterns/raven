<?php

/**
 * RAVEN CMS
 * ~/private/ext/smallweb/ext.php
 * Smallweb extension service bootstrap provider.
 * docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use Raven\Smallweb\SmallwebService;

/**
 * Registers Smallweb extension services into the shared app container.
 */
return [
    'storage' => [
        'local' => true,
    ],
    'boot' => static function (array &$app): void {
    if (!isset($app['root'], $app['config'], $app['extension_storage'])) {
        return;
    }

    $root = rtrim((string) $app['root'], '/');
    $storageMap = is_array($app['extension_storage']) ? $app['extension_storage'] : [];
    $storage = is_array($storageMap['smallweb'] ?? null) ? $storageMap['smallweb'] : [];
    $storageDir = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($storageDir === '') {
        return;
    }
    $service = new SmallwebService($root, $storageDir, $app['config']);

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $app['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawSmallwebServices */
    $rawSmallwebServices = $rawExtensionServices['smallweb'] ?? [];
    if (!is_array($rawSmallwebServices)) {
        $rawSmallwebServices = [];
    }

    $rawSmallwebServices['service'] = $service;
    $rawExtensionServices['smallweb'] = $rawSmallwebServices;
    $app['extension_services'] = $rawExtensionServices;
    },
];
