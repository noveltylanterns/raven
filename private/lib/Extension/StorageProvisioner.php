<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/StorageProvisioner.php
 * Creates extension-owned local storage directories and asset mirrors.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

use RuntimeException;

/**
 * Creates extension-owned local storage directories and asset mirrors.
 */
final class StorageProvisioner
{
    private string $projectRoot;
    private ValidateManifest $manifestValidator;

    /**
     * Prepares the storage provisioner for one project tree.
     *
     * @param string $projectRoot Absolute project root path.
     * @param ValidateManifest|null $manifestValidator Optional validator; defaults to a fresh instance.
     */
    public function __construct(string $projectRoot, ?ValidateManifest $manifestValidator = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->manifestValidator = $manifestValidator ?? new ValidateManifest();
    }

    /**
     * Ensures the local extension data directory exists and returns its absolute path.
     *
     * @param string $directoryName Extension directory name (slug).
     * @return string Absolute path to the created or existing `private/dat/ext/{slug}` directory.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public function ensureLocalStorageDirectory(string $directoryName): string
    {
        return $this->ensureDirectory($this->projectRoot . '/private/dat/ext', $directoryName, 'private/dat/ext');
    }

    /**
     * Ensures the panel asset directory exists and returns its absolute path.
     *
     * @param string $directoryName Extension directory name (slug).
     * @return string Absolute path to the created or existing `panel/ext/{slug}` directory.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public function ensurePanelStorageDirectory(string $directoryName): string
    {
        return $this->ensureDirectory($this->projectRoot . '/panel/ext', $directoryName, 'panel/ext');
    }

    /**
     * Ensures the public uploads directory exists and returns its absolute path.
     *
     * @param string $directoryName Extension directory name (slug).
     * @return string Absolute path to the created or existing `public/uploads/ext/{slug}` directory.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public function ensurePublicStorageDirectory(string $directoryName): string
    {
        return $this->ensureDirectory($this->projectRoot . '/public/uploads/ext', $directoryName, 'public/uploads/ext');
    }

    /**
     * Ensures a root-level aux directory exists and returns its absolute path.
     *
     * @param string $directoryName Aux root directory name (slug); must be safe and non-reserved.
     * @return string Absolute path to the created or existing aux directory.
     *
     * @throws RuntimeException When the name is invalid, reserved, blocked by a file, or cannot be created.
     */
    public function ensureAuxStorageDirectory(string $directoryName): string
    {
        // Aux directory names must match the safe root-directory slug pattern.
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $directoryName) !== 1) {
            throw new RuntimeException('Invalid extension aux storage directory name.');
        }

        $reserved = ['composer', 'debug', 'docs', 'panel', 'private', 'public'];
        // Block reserved top-level project directories from aux allocation.
        if (in_array(strtolower($directoryName), $reserved, true)) {
            throw new RuntimeException('Reserved root directory name cannot be used for extension aux storage.');
        }

        $targetPath = $this->projectRoot . '/' . $directoryName;
        // Prevent replacing existing files with directory targets.
        if (is_file($targetPath)) {
            throw new RuntimeException('Failed to create aux/' . $directoryName . ' directory because a file already exists there.');
        }

        // Create aux directory recursively and verify success.
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
        // Bin symlink provisioning requires a safe extension directory slug.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for bin storage.');
        }

        $sourceBin = $this->projectRoot . '/private/ext/' . $directoryName . '/bin';
        // No extension bin directory means nothing to symlink.
        if (!is_dir($sourceBin)) {
            // No bin/ directory in the extension; nothing to link.
            return;
        }

        $targetBin = $this->projectRoot . '/private/bin';
        // Ensure shared private/bin target directory exists.
        if (!is_dir($targetBin) && !mkdir($targetBin, 0775, true) && !is_dir($targetBin)) {
            throw new RuntimeException('Failed to ensure private/bin directory for extension bin storage.');
        }

        // Create one symlink per file in the extension bin/ directory.
        // Only files are linked; subdirectories are ignored since CLI commands are single files.
        $iterator = new \DirectoryIterator($sourceBin);
        // Create symlinks for each eligible source-bin file.
        foreach ($iterator as $item) {
            // Skip dot entries and nested directories.
            if ($item->isDot() || $item->isDir()) {
                continue;
            }

            $name = $item->getFilename();
            // Guard against traversal via unexpected filenames.
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name) !== 1) {
                continue;
            }

            $linkPath = $targetBin . '/' . $name;
            // Resolve from private/bin so the alias remains portable across installations.
            $targetPath = '../ext/' . $directoryName . '/bin/' . $name;

            // Repair aliases created by older versions when their absolute target is stale
            // or points at a different extension command; this also makes provisioning
            // self-healing after an update moves the project tree.
            if (is_link($linkPath)) {
                if (readlink($linkPath) === $targetPath) {
                    // The existing relative alias is already portable and correct.
                    continue;
                }

                if (!unlink($linkPath)) {
                    throw new RuntimeException('Failed to replace stale bin symlink for "' . $name . '" in private/bin/.');
                }
            }

            // Never overwrite an existing non-symlink file at alias location.
            if (file_exists($linkPath)) {
                // A real file already occupies the name; do not clobber it.
                throw new RuntimeException('Cannot create bin symlink for "' . $name . '": a non-symlink file already exists at private/bin/' . $name . '.');
            }

            // Create alias symlink from private/bin to extension bin executable.
            if (!symlink($targetPath, $linkPath)) {
                throw new RuntimeException('Failed to create bin symlink for "' . $name . '" in private/bin/.');
            }
        }
    }

    /**
     * Provisions all storage declared in one extension bootstrap contract.
     *
     * Creates local data directories, aux directories, panel/public asset directories,
     * and bin symlinks as specified. Does not run database schema migrations — those
     * are handled separately via the extension schema provider.
     *
     * @param string $directoryName Extension directory name (slug).
     * @param array{
     *   local?: bool,
     *   table?: bool,
     *   tables?: array<int, string>,
     *   aux?: array<int, string>,
     *   panel?: bool,
     *   public?: bool,
     *   bin?: bool
     * } $storage Storage contract flags from Bootstrap::resolve().
     * @return void
     *
     * @throws RuntimeException When any storage directory or symlink cannot be provisioned.
     */
    public function provision(string $directoryName, array $storage): void
    {
        // Storage provisioning requires a safe extension directory slug.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for storage provisioning.');
        }

        // Provision local data directory when requested by contract.
        if (!empty($storage['local'])) {
            $this->ensureLocalStorageDirectory($directoryName);
        }

        // Provision each declared auxiliary storage directory.
        foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
            // Skip malformed aux directory entries.
            if (!is_string($auxDirectory)) {
                continue;
            }

            $this->ensureAuxStorageDirectory($auxDirectory);
        }

        // Provision panel asset directory and sync bundled panel assets.
        if (!empty($storage['panel'])) {
            $target = $this->ensurePanelStorageDirectory($directoryName);
            $this->syncBundledAssets($directoryName, 'panel', $target);
        }

        // Provision public asset directory and sync bundled public assets.
        if (!empty($storage['public'])) {
            $target = $this->ensurePublicStorageDirectory($directoryName);
            $this->syncBundledAssets($directoryName, 'public', $target);
        }

        // Provision private/bin symlink aliases when requested by contract.
        if (!empty($storage['bin'])) {
            $this->ensureBinSymlinks($directoryName);
        }
    }

    /**
     * Ensures a subdirectory under `$basePath` exists and returns its path.
     *
     * @param string $basePath Absolute parent directory path.
     * @param string $directoryName Extension slug to append as the subdirectory name.
     * @param string $label Human-readable base-path label used in exception messages.
     * @return string Absolute path to the provisioned subdirectory.
     *
     * @throws RuntimeException When the name is invalid or the directory cannot be created.
     */
    private function ensureDirectory(string $basePath, string $directoryName, string $label): string
    {
        // Directory provisioning requires a safe extension slug.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for ' . $label . ' storage.');
        }

        // Ensure base path exists before creating extension-specific child directory.
        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new RuntimeException('Failed to create ' . $label . ' directory.');
        }

        $targetPath = $basePath . '/' . $directoryName;
        // Ensure extension-specific storage directory exists.
        if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
            throw new RuntimeException('Failed to create ' . $label . '/' . $directoryName . ' directory.');
        }

        return $targetPath;
    }

    /**
     * Copies bundled extension assets from `assets/{scope}/` into the provisioned storage directory.
     *
     * The source is `private/ext/{slug}/assets/{scope}/`; files and subdirectories are mirrored
     * recursively into `$targetRoot`. No-ops silently when the source directory does not exist.
     *
     * @param string $directoryName Extension directory name (slug).
     * @param string $scope Asset scope token: `panel` or `public`.
     * @param string $targetRoot Absolute target directory path to copy files into.
     * @return void
     *
     * @throws RuntimeException When an asset directory or file cannot be created or copied.
     */
    private function syncBundledAssets(string $directoryName, string $scope, string $targetRoot): void
    {
        $sourceRoot = $this->projectRoot . '/private/ext/' . $directoryName . '/assets/' . $scope;
        // Missing bundled-asset source directory is a valid no-op.
        if (!is_dir($sourceRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Mirror every file/directory from source assets into target storage root.
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
            // Skip unresolved/empty relative paths.
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $targetPath = $targetRoot . '/' . str_replace('\\', '/', $relative);
            // Create destination directory nodes before copying nested files.
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new RuntimeException('Failed to create extension ' . $scope . ' asset directory.');
                }
                continue;
            }

            $parent = dirname($targetPath);
            // Ensure parent directory exists before copying a file payload.
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Failed to prepare extension ' . $scope . ' asset directory.');
            }

            // Copy source asset file into provisioned storage path.
            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('Failed to copy bundled extension ' . $scope . ' asset: ' . $relative);
            }
        }
    }
}
