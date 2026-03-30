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
use Raven\Repo\RepoLogStore;
use Raven\Repo\RepoRegistryStore;
use Raven\Repo\RepoService;
use Raven\Repo\RepoSettingsStore;
use Raven\Repo\RepoShortcodeRuntime;

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

        $settingsStore = new RepoSettingsStore($localRoot . '/.settings.json');
        $registryStore = new RepoRegistryStore($localRoot . '/.registry.json');
        $logStore = new RepoLogStore($localRoot . '/.log.json');
        $service = new RepoService(
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
        $shortcodeRuntime = new RepoShortcodeRuntime($service);

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

        $rawRepoServices['settings'] = $settingsStore;
        $rawRepoServices['registry'] = $registryStore;
        $rawRepoServices['logs'] = $logStore;
        $rawRepoServices['service'] = $service;

        /** @var mixed $rawShortcodeRuntimes */
        $rawShortcodeRuntimes = $rawRepoServices['shortcode_runtimes'] ?? [];
        if (!is_array($rawShortcodeRuntimes)) {
            $rawShortcodeRuntimes = [];
        }
        $rawShortcodeRuntimes[] = $shortcodeRuntime;
        $rawRepoServices['shortcode_runtimes'] = $rawShortcodeRuntimes;

        $rawExtensionServices['repo'] = $rawRepoServices;
        $rvn['extension_services'] = $rawExtensionServices;
    },
];