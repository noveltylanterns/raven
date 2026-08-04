<?php

/**
 * RAVEN CMS
 * ~/private/ext/backup/ext.php
 * Backup & Restore extension service bootstrap provider.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

/**
 * Registers extension-owned services and declares optional storage requests.
 */
return [
    'storage' => [
        // Supported options:
        // 'local' => true,
        // 'table' => true,
        // 'tables' => ['items'],
        // 'aux' => ['finger'],
        // 'panel' => true,
        // 'public' => true,
    ],
    'boot' => static function (array &$rvn): void {
    $extensionKey = 'backup';

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $rvn['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawServices */
    $rawServices = $rawExtensionServices[$extensionKey] ?? [];
    if (!is_array($rawServices)) {
        $rawServices = [];
    }

    // Resolved storage roots, when requested by this extension:
    // $storage = is_array($rvn['extension_storage'][$extensionKey] ?? null) ? $rvn['extension_storage'][$extensionKey] : [];
    // $localRoot = (string) ($storage['local'] ?? '');
    // $auxRoots = is_array($storage['aux'] ?? null) ? $storage['aux'] : [];
    // $panelRoot = (string) ($storage['panel'] ?? '');
    // $publicRoot = (string) ($storage['public'] ?? '');

    // Register extension services here, for example:
    // $rawServices['repository'] = new MyRepository(...);

    $rawExtensionServices[$extensionKey] = $rawServices;
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
