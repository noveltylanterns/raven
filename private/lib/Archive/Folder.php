<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Folder.php
 * Directory creation, existence, and removal helpers for install and cleanup flows.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

/**
 * Directory creation, existence enforcement, and removal helpers.
 *
 * Walks the tree bottom-up during recursive removal so children are removed
 * before their parents, which is required on most filesystems.
 */
final class Folder
{
    /**
     * Ensures one directory exists and applies the requested mode.
     *
     * @param string $directory Absolute directory path.
     * @param int $mode Octal directory mode to enforce.
     * @return bool True when the directory exists after the call.
     */
    public function ensure(string $directory, int $mode = 0775): bool
    {
        // Create the directory recursively when it does not already exist.
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            return false;
        }

        @chmod($directory, $mode);
        return true;
    }

    /**
     * Creates one direct child directory and applies the requested mode.
     *
     * @param string $directory Absolute directory path.
     * @param int $mode Octal directory mode to apply.
     * @return bool True when the directory was created successfully.
     */
    public function create(string $directory, int $mode = 0775): bool
    {
        // Refuse to create when a file/dir already occupies the target path.
        if (is_dir($directory) || is_file($directory)) {
            return false;
        }

        // Create exactly one directory level for this explicit child create helper.
        if (!@mkdir($directory, $mode, false)) {
            return false;
        }

        @chmod($directory, $mode);
        return true;
    }

    /**
     * Removes one empty directory.
     *
     * @param string $directory Absolute directory path.
     * @return bool True when the directory was removed or already absent.
     */
    public function removeEmpty(string $directory): bool
    {
        // Missing directories are treated as already-removed for idempotence.
        if (!is_dir($directory)) {
            return true;
        }

        $entries = @scandir($directory);
        // Fail when the directory cannot be enumerated safely.
        if ($entries === false) {
            return false;
        }

        // Reject removal when any non-dot entry exists.
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return @rmdir($directory);
    }

    /**
     * Removes a directory and all of its contents recursively.
     *
     * No-ops silently when the directory does not exist, so callers may call
     * this unconditionally without pre-checking existence.
     *
     * @param string $directory Absolute path to the directory to remove.
     * @return void
     */
    public function removeTree(string $directory): void
    {
        // Missing directories are treated as no-op for idempotence.
        if (!is_dir($directory)) {
            return;
        }

        // CHILD_FIRST ensures files and subdirs are removed before their parent dirs.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        // Remove children before parent directories to satisfy filesystem constraints.
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
