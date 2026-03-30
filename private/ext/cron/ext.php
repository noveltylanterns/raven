<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/ext.php
 * Scheduled Tasks extension service bootstrap provider.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

use Raven\Cron\CronShellRunner;
use Raven\Cron\CronTaskService;
use Raven\Cron\CronTaskStore;

/**
 * Registers Scheduled Tasks services into the shared app container.
 */
return [
    'storage' => [
        'local' => true,
    ],
    'scheduler' => true,
    'boot' => static function (array &$rvn): void {
        if (!isset($rvn['root'], $rvn['extension_storage'])) {
            return;
        }

        /** @var mixed $rawStorageMap */
        $rawStorageMap = $rvn['extension_storage'];
        $storageMap = is_array($rawStorageMap) ? $rawStorageMap : [];
        $storage = is_array($storageMap['cron'] ?? null) ? $storageMap['cron'] : [];
        $localRoot = rtrim((string) ($storage['local'] ?? ''), '/');
        if ($localRoot === '') {
            return;
        }

        $store = new CronTaskStore($localRoot . '/crontab.json');
        $service = new CronTaskService(
            (string) $rvn['root'],
            $store,
            new CronShellRunner()
        );

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        if (!is_array($rawExtensionServices)) {
            $rawExtensionServices = [];
        }

        /** @var mixed $rawCronServices */
        $rawCronServices = $rawExtensionServices['cron'] ?? [];
        if (!is_array($rawCronServices)) {
            $rawCronServices = [];
        }

        $rawCronServices['store'] = $store;
        $rawCronServices['service'] = $service;

        $rawExtensionServices['cron'] = $rawCronServices;
        $rvn['extension_services'] = $rawExtensionServices;
    },
];
