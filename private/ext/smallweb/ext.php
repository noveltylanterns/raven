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
 *
 * @param array<string, mixed> $app
 */
return static function (array &$app): void {
    if (!isset($app['root'], $app['config'])) {
        return;
    }

    $root = rtrim((string) $app['root'], '/');
    $storageDir = $root . '/private/dat/ext/smallweb';
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
};
