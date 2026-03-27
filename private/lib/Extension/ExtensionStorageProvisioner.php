<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

use RuntimeException;

/**
 * Creates extension-owned local storage directories and asset mirrors.
 */
final class ExtensionStorageProvisioner
{
    private string $projectRoot;
    private ManifestContractValidator $manifestValidator;

    public function __construct(string $projectRoot, ?ManifestContractValidator $manifestValidator = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->manifestValidator = $manifestValidator ?? new ManifestContractValidator();
    }

    public function ensureLocalStorageDirectory(string $directoryName): string
    {
        return $this->ensureDirectory($this->projectRoot . '/private/dat/ext', $directoryName, 'private/dat/ext');
    }

    public function ensurePanelStorageDirectory(string $directoryName): string
    {
        return $this->ensureDirectory($this->projectRoot . '/panel/ext', $directoryName, 'panel/ext');
    }

    public function ensurePublicStorageDirectory(string $directoryName): string
    {
        return $this->ensureDirectory($this->projectRoot . '/public/upload/ext', $directoryName, 'public/upload/ext');
    }

    /**
     * @param array{
     *   local?: bool,
     *   panel?: bool,
     *   public?: bool
     * } $storage
     */
    public function provision(string $directoryName, array $storage): void
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for storage provisioning.');
        }

        if (!empty($storage['local'])) {
            $this->ensureLocalStorageDirectory($directoryName);
        }

        if (!empty($storage['panel'])) {
            $target = $this->ensurePanelStorageDirectory($directoryName);
            $this->syncBundledAssets($directoryName, 'panel', $target);
        }

        if (!empty($storage['public'])) {
            $target = $this->ensurePublicStorageDirectory($directoryName);
            $this->syncBundledAssets($directoryName, 'public', $target);
        }
    }

    private function ensureDirectory(string $basePath, string $directoryName, string $label): string
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for ' . $label . ' storage.');
        }

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new RuntimeException('Failed to create ' . $label . ' directory.');
        }

        $targetPath = $basePath . '/' . $directoryName;
        if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
            throw new RuntimeException('Failed to create ' . $label . '/' . $directoryName . ' directory.');
        }

        return $targetPath;
    }

    private function syncBundledAssets(string $directoryName, string $scope, string $targetRoot): void
    {
        $sourceRoot = $this->projectRoot . '/private/ext/' . $directoryName . '/assets/' . $scope;
        if (!is_dir($sourceRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $targetPath = $targetRoot . '/' . str_replace('\\', '/', $relative);
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new RuntimeException('Failed to create extension ' . $scope . ' asset directory.');
                }
                continue;
            }

            $parent = dirname($targetPath);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Failed to prepare extension ' . $scope . ' asset directory.');
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('Failed to copy bundled extension ' . $scope . ' asset: ' . $relative);
            }
        }
    }
}
