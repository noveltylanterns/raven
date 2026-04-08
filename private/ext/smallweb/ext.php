<?php

/**
 * RAVEN CMS
 * ~/private/ext/smallweb/ext.php
 * Smallweb extension service bootstrap provider.
 * docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use Raven\Ext\Smallweb\SmallwebService;

/**
 * Registers Smallweb extension services into the shared app container.
 */
return [
    'storage' => [
        'local' => true,
    ],
    'boot' => static function (array &$rvn): void {
    if (!isset($rvn['root'], $rvn['config'], $rvn['extension_storage'])) {
        return;
    }

    $root = rtrim((string) $rvn['root'], '/');
    $storageMap = is_array($rvn['extension_storage']) ? $rvn['extension_storage'] : [];
    $storage = is_array($storageMap['smallweb'] ?? null) ? $storageMap['smallweb'] : [];
    $storageDir = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($storageDir === '') {
        return;
    }
    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $rvn['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawSmallwebServices */
    $rawSmallwebServices = $rawExtensionServices['smallweb'] ?? [];
    if (!is_array($rawSmallwebServices)) {
        $rawSmallwebServices = [];
    }

    $rawSmallwebServices['service'] = static fn (): SmallwebService => new SmallwebService($root, $storageDir, $rvn['config']);
    $rawExtensionServices['smallweb'] = $rawSmallwebServices;
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
