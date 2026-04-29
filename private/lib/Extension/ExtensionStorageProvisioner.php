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
    private ValidateManifest $manifestValidator;

    public function __construct(string $projectRoot, ?ValidateManifest $manifestValidator = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->manifestValidator = $manifestValidator ?? new ValidateManifest();
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
        return $this->ensureDirectory($this->projectRoot . '/public/uploads/ext', $directoryName, 'public/uploads/ext');
    }

    public function ensureAuxStorageDirectory(string $directoryName): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $directoryName) !== 1) {
            throw new RuntimeException('Invalid extension aux storage directory name.');
        }

        $reserved = ['composer', 'debug', 'docs', 'panel', 'private', 'public'];
        if (in_array(strtolower($directoryName), $reserved, true)) {
            throw new RuntimeException('Reserved root directory name cannot be used for extension aux storage.');
        }

        $targetPath = $this->projectRoot . '/' . $directoryName;
        if (is_file($targetPath)) {
            throw new RuntimeException('Failed to create aux/' . $directoryName . ' directory because a file already exists there.');
        }

        if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
            throw new RuntimeException('Failed to create aux/' . $directoryName . ' directory.');
        }

        return $targetPath;
    }

    /**
     * Creates symlinks in private/bin/ pointing to executables in the extension's bin/ directory.
     *
     * @param string $directoryName Extension directory name (used for path construction only; symlink names come from the bin/ contents).
     * @throws RuntimeException If the symlink source directory exists but a symlink cannot be created.
     */
    public function ensureBinSymlinks(string $directoryName): void
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for bin storage.');
        }

        $sourceBin = $this->projectRoot . '/private/ext/' . $directoryName . '/bin';
        if (!is_dir($sourceBin)) {
            // No bin/ directory in the extension; nothing to link.
            return;
        }

        $targetBin = $this->projectRoot . '/private/bin';
        if (!is_dir($targetBin) && !mkdir($targetBin, 0775, true) && !is_dir($targetBin)) {
            throw new RuntimeException('Failed to ensure private/bin directory for extension bin storage.');
        }

        // Create one symlink per file in the extension bin/ directory.
        // Only files are linked; subdirectories are ignored since CLI commands are single files.
        $iterator = new \DirectoryIterator($sourceBin);
        foreach ($iterator as $item) {
            if ($item->isDot() || $item->isDir()) {
                continue;
            }

            $name = $item->getFilename();
            // Guard against traversal via unexpected filenames.
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name) !== 1) {
                continue;
            }

            $linkPath = $targetBin . '/' . $name;
            $targetPath = $item->getRealPath();

            if (is_link($linkPath)) {
                // Already linked; skip (idempotent).
                continue;
            }

            if (file_exists($linkPath)) {
                // A real file already occupies the name; do not clobber it.
                throw new RuntimeException('Cannot create bin symlink for "' . $name . '": a non-symlink file already exists at private/bin/' . $name . '.');
            }

            if (!symlink($targetPath, $linkPath)) {
                throw new RuntimeException('Failed to create bin symlink for "' . $name . '" in private/bin/.');
            }
        }
    }

    /**
     * @param array{
     *   local?: bool,
     *   table?: bool,
     *   tables?: array<int, string>,
     *   aux?: array<int, string>,
     *   panel?: bool,
     *   public?: bool,
     *   bin?: bool
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

        foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
            if (!is_string($auxDirectory)) {
                continue;
            }

            $this->ensureAuxStorageDirectory($auxDirectory);
        }

        if (!empty($storage['panel'])) {
            $target = $this->ensurePanelStorageDirectory($directoryName);
            $this->syncBundledAssets($directoryName, 'panel', $target);
        }

        if (!empty($storage['public'])) {
            $target = $this->ensurePublicStorageDirectory($directoryName);
            $this->syncBundledAssets($directoryName, 'public', $target);
        }

        if (!empty($storage['bin'])) {
            $this->ensureBinSymlinks($directoryName);
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
