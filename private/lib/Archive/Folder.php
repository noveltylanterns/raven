<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Folder.php
 * Recursive directory-removal utility for uninstall and cleanup flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

/**
 * Recursive directory-removal helper for uninstall and cleanup flows.
 *
 * Walks the tree bottom-up so children are removed before their parents,
 * which is required when removing non-empty directories on most filesystems.
 */
final class Folder
{
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
        if (!is_dir($directory)) {
            return;
        }

        // CHILD_FIRST ensures files and subdirs are removed before their parent dirs.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
