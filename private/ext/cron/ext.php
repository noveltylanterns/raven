<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/ext.php
 * Scheduled Tasks extension service bootstrap provider.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Ext\Cron\CronShellRunner;
use Raven\Ext\Cron\CronTaskService;
use Raven\Ext\Cron\CronTaskStore;

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

        $rawCronServices['store'] = static fn (): CronTaskStore => new CronTaskStore($localRoot . '/crontab.json');
        $rawCronServices['service'] = static function () use (&$rvn): CronTaskService {
            /** @var mixed $resolver */
            $resolver = $rvn['extension_services_for'] ?? null;
            $services = is_callable($resolver) ? $resolver('cron') : [];
            $store = $services['store'] ?? null;
            if (!$store instanceof CronTaskStore) {
                throw new RuntimeException('Scheduled Tasks store is unavailable.');
            }

            return new CronTaskService(
                (string) $rvn['root'],
                $store,
                new CronShellRunner()
            );
        };

        $rawExtensionServices['cron'] = $rawCronServices;
        $rvn['extension_services'] = $rawExtensionServices;
    },
];
