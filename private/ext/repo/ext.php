<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/ext.php
 * Repositories extension service bootstrap provider.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

use Raven\Lib\Filesystem\DirectoryTreeService;
use Raven\Lib\Update\GitCommandRunner;
use Raven\Ext\Repo\RepoLogStore;
use Raven\Ext\Repo\RepoRegistryStore;
use Raven\Ext\Repo\RepoService;
use Raven\Ext\Repo\RepoSettingsStore;
use Raven\Ext\Repo\RepoShortcodeRuntime;

/**
 * Registers Repositories extension services into the shared app container.
 */
return [
    'storage' => [
        'local' => true,
        'public' => true,
        'bin' => true,
    ],
    'scheduler' => true,
    'boot' => static function (array &$rvn): void {
        if (!isset($rvn['root'], $rvn['config'], $rvn['extension_storage'])) {
            return;
        }

        /** @var mixed $rawStorageMap */
        $rawStorageMap = $rvn['extension_storage'];
        $storageMap = is_array($rawStorageMap) ? $rawStorageMap : [];
        $storage = is_array($storageMap['repo'] ?? null) ? $storageMap['repo'] : [];
        $localRoot = rtrim((string) ($storage['local'] ?? ''), '/');
        $publicRoot = rtrim((string) ($storage['public'] ?? ''), '/');
        if ($localRoot === '' || $publicRoot === '') {
            return;
        }

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        if (!is_array($rawExtensionServices)) {
            $rawExtensionServices = [];
        }

        /** @var mixed $rawRepoServices */
        $rawRepoServices = $rawExtensionServices['repo'] ?? [];
        if (!is_array($rawRepoServices)) {
            $rawRepoServices = [];
        }

        $rawRepoServices['settings'] = static fn (): RepoSettingsStore => new RepoSettingsStore($localRoot . '/.settings.json');
        $rawRepoServices['registry'] = static fn (): RepoRegistryStore => new RepoRegistryStore($localRoot . '/.registry.json');
        $rawRepoServices['logs'] = static fn (): RepoLogStore => new RepoLogStore($localRoot . '/.log.json');
        $rawRepoServices['service'] = static function () use (&$rvn, $localRoot, $publicRoot): RepoService {
            /** @var mixed $resolver */
            $resolver = $rvn['extension_services_for'] ?? null;
            $services = is_callable($resolver) ? $resolver('repo') : [];
            $settingsStore = $services['settings'] ?? null;
            $registryStore = $services['registry'] ?? null;
            $logStore = $services['logs'] ?? null;
            if (
                !$settingsStore instanceof RepoSettingsStore
                || !$registryStore instanceof RepoRegistryStore
                || !$logStore instanceof RepoLogStore
            ) {
                throw new RuntimeException('Repo extension stores are unavailable.');
            }

            return new RepoService(
                (string) $rvn['root'],
                $rvn['config'],
                $settingsStore,
                $registryStore,
                $logStore,
                new GitCommandRunner(),
                new DirectoryTreeService(),
                $localRoot,
                $publicRoot
            );
        };

        /** @var mixed $rawShortcodeRuntimes */
        $rawShortcodeRuntimes = $rawRepoServices['shortcode_runtimes'] ?? [];
        if (!is_array($rawShortcodeRuntimes)) {
            $rawShortcodeRuntimes = [];
        }
        $rawShortcodeRuntimes[] = static function () use (&$rvn): RepoShortcodeRuntime {
            /** @var mixed $resolver */
            $resolver = $rvn['extension_services_for'] ?? null;
            $services = is_callable($resolver) ? $resolver('repo') : [];
            $service = $services['service'] ?? null;
            if (!$service instanceof RepoService) {
                throw new RuntimeException('Repo extension service is unavailable.');
            }

            return new RepoShortcodeRuntime($service);
        };
        $rawRepoServices['shortcode_runtimes'] = $rawShortcodeRuntimes;

        $rawExtensionServices['repo'] = $rawRepoServices;
        $rvn['extension_services'] = $rawExtensionServices;
    },
];
