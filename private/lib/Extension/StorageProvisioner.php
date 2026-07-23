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
        // Preserve the aux bucket's designated root while rejecting symlink escapes.
        if (!Resolver::isSymlinkFreePath($targetPath)) {
            throw new RuntimeException('Aux storage path contains an unsupported symlink.');
        }

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
     * Provisions regular private/bin launchers for extension-local CLI commands.
     *
     * @param string $directoryName Extension directory name whose commands should be exposed.
     * @return void
     * @throws RuntimeException When the extension directory name is invalid or a launcher cannot be written.
     */
    public function ensureBinStorage(string $directoryName): void
    {
        // Bin storage provisioning requires a safe extension directory slug.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            throw new RuntimeException('Invalid extension directory name for bin storage.');
        }

        $sourceBin = $this->projectRoot . '/private/ext/' . $directoryName . '/bin';
        // No extension bin directory means there are no commands to expose.
        if (!is_dir($sourceBin)) {
            return;
        }

        // Extension executable roots must not contain links, even when the target stays local.
        if (is_link($sourceBin) || !Resolver::isSafeExtensionRoot(dirname($sourceBin))) {
            throw new RuntimeException('Extension bin storage contains an unsupported symlink.');
        }

        $targetBin = $this->projectRoot . '/private/bin';
        // Ensure the shared launcher bucket exists without creating any symlink.
        if (!is_dir($targetBin) && !mkdir($targetBin, 0775, true) && !is_dir($targetBin)) {
            throw new RuntimeException('Failed to ensure private/bin directory for extension bin storage.');
        }

        // Create one ordinary launcher per extension-owned command.
        foreach (new \DirectoryIterator($sourceBin) as $item) {
            if ($item->isDot() || $item->isDir()) {
                continue;
            }

            $name = $item->getFilename();
            // Guard launcher names against traversal and shell-metacharacter surprises.
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name) !== 1) {
                continue;
            }

            $sourcePath = $sourceBin . '/' . $name;
            if (!is_file($sourcePath) || is_link($sourcePath)) {
                throw new RuntimeException('Extension bin command must be a regular file: ' . $sourcePath);
            }

            $launcherPath = $targetBin . '/' . $name;
            $launcherContent = $this->binLauncherContent($directoryName, $name);
            $existing = is_file($launcherPath) ? file_get_contents($launcherPath) : false;

            // Never replace stock commands or unrelated operator files.
            if (is_link($launcherPath)) {
                throw new RuntimeException('Cannot create bin launcher over a symlink: ' . $launcherPath);
            }
            if (file_exists($launcherPath) && ($existing === false || !str_contains($existing, 'RAVEN EXTENSION BIN LAUNCHER: ' . $directoryName . '/' . $name))) {
                throw new RuntimeException('Cannot create bin launcher over an existing file: ' . $launcherPath);
            }

            if ($existing !== $launcherContent && file_put_contents($launcherPath, $launcherContent, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write extension bin launcher: ' . $launcherPath);
            }

            @chmod($launcherPath, 0755);
        }
    }

    /**
     * Builds one root-contained launcher for an extension-local CLI command.
     *
     * @param string $directoryName Extension directory name.
     * @param string $commandName   Command filename.
     * @return string Launcher PHP source.
     */
    private function binLauncherContent(string $directoryName, string $commandName): string
    {
        $template = <<<'PHP'
#!/usr/bin/env php
<?php

/**
 * RAVEN EXTENSION BIN LAUNCHER: __DIRECTORY__/__COMMAND__
 * Generated local launcher; the executable remains in the extension bin directory.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$extensionBin = $projectRoot . '/private/ext/__DIRECTORY__/bin';
$targetPath = $extensionBin . '/__COMMAND__';
$resolvedRoot = realpath($projectRoot);
$resolvedBin = realpath($extensionBin);
$resolvedTarget = realpath($targetPath);
$rootPrefix = $resolvedRoot === false ? '' : rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
$binPrefix = $resolvedBin === false ? '' : rtrim(str_replace('\\', '/', $resolvedBin), '/') . '/';
$normalizedTarget = $resolvedTarget === false ? '' : str_replace('\\', '/', $resolvedTarget);
if (
    $resolvedRoot === false
    || $resolvedBin === false
    || $resolvedTarget === false
    || is_link($extensionBin)
    || is_link($targetPath)
    || !str_starts_with($normalizedTarget, $rootPrefix)
    || !str_starts_with($normalizedTarget, $binPrefix)
) {
    fwrite(STDERR, "Extension bin launcher target is unavailable or outside Raven.\n");
    exit(1);
}

require $resolvedTarget;
PHP;

        return str_replace(
            ['__DIRECTORY__', '__COMMAND__'],
            [$directoryName, $commandName],
            $template
        ) . "\n";
    }

    /**
     * Provisions all storage declared in one extension bootstrap contract.
     *
     * Creates local data directories, aux directories, panel/public asset directories,
     * and regular private/bin launchers as specified. Does not run database schema migrations — those
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
     * @throws RuntimeException When any storage directory or launcher cannot be provisioned.
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

        // Remove legacy private/bin aliases when extension CLI storage is requested.
        if (!empty($storage['bin'])) {
            $this->ensureBinStorage($directoryName);
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

        // Each storage bucket keeps its designated Raven location but may not traverse a symlink.
        if (!Resolver::isSymlinkFreePath($basePath)) {
            throw new RuntimeException($label . ' storage base contains an unsupported symlink.');
        }

        // Ensure base path exists before creating extension-specific child directory.
        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new RuntimeException('Failed to create ' . $label . ' directory.');
        }

        $targetPath = $basePath . '/' . $directoryName;
        if (!Resolver::isSymlinkFreePath($targetPath)) {
            throw new RuntimeException($label . ' storage path contains an unsupported symlink.');
        }

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

        // Bundled asset sources must remain physically inside the extension tree.
        if (!Resolver::isSymlinkFreePath($sourceRoot)) {
            throw new RuntimeException('Extension ' . $scope . ' asset source contains an unsupported symlink.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Mirror every file/directory from source assets into target storage root.
        foreach ($iterator as $item) {
            // Reject linked source entries before isDir()/copy() can follow them.
            if (!Resolver::isSymlinkFreePath($item->getPathname())) {
                throw new RuntimeException('Extension ' . $scope . ' asset source contains an unsupported symlink.');
            }

            $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
            // Skip unresolved/empty relative paths.
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $targetPath = $targetRoot . '/' . str_replace('\\', '/', $relative);
            if (!Resolver::isSymlinkFreePath($targetPath)) {
                throw new RuntimeException('Extension ' . $scope . ' asset path contains an unsupported symlink.');
            }
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
