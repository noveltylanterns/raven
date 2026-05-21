<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/MediaStorage.php
 * Shared page-media path layout helpers for upload and cleanup workflows.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Lib\Archive\Folder;

/**
 * Shared page-media storage path layout and cleanup helpers.
 */
final class MediaStorage
{
    private string $projectRoot;
    private Folder $folder;

    /**
     * @param string $projectRoot Absolute project root for public upload-path resolution.
     * @return void
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->folder = new Folder();
    }

    /**
     * Returns the absolute page gallery directory path for one page id.
     *
     * @param int $pageId Owning page id.
     * @return string Absolute gallery directory path.
     */
    public function pageDirectory(int $pageId): string
    {
        return $this->projectRoot . '/public/uploads/pages/' . $pageId;
    }

    /**
     * Ensures the page gallery directory exists.
     *
     * @param int $pageId Owning page id.
     * @return bool True when the directory exists after the call.
     */
    public function ensurePageDirectory(int $pageId): bool
    {
        $directory = $this->pageDirectory($pageId);
        return $this->folder->ensure($directory, 0775);
    }

    /**
     * Builds the stored relative path for a page-gallery filename.
     *
     * @param int $pageId Owning page id.
     * @param string $filename Gallery filename.
     * @return string Stored relative path.
     */
    public function storedPathForFilename(int $pageId, string $filename): string
    {
        return 'uploads/pages/' . $pageId . '/' . ltrim($filename, '/');
    }

    /**
     * Converts a stored relative path into its absolute public filesystem path.
     *
     * @param string $storedPath Stored relative path.
     * @return string Absolute filesystem path.
     */
    public function absolutePublicPath(string $storedPath): string
    {
        return $this->projectRoot . '/public/' . ltrim($storedPath, '/');
    }

    /**
     * Deletes one stored relative file path if it resolves inside gallery storage.
     */
    public function deleteStoredPath(string $storedPath): void
    {
        $normalized = ltrim($storedPath, '/');

        // Reject empty and traversal-like inputs before resolving absolute filesystem paths.
        if ($normalized === '' || str_contains($normalized, '..')) {
            return;
        }

        // Only page-gallery paths are owned by this storage helper.
        if (!str_starts_with($normalized, 'uploads/pages/')) {
            return;
        }

        $absolutePath = $this->absolutePublicPath($normalized);
        // Deletion is best-effort and only applies to regular files.
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * Removes the page gallery directory when it becomes empty.
     *
     * @param int $pageId Owning page id.
     * @return void
     */
    public function removePageDirectory(int $pageId): void
    {
        $this->folder->removeEmpty($this->pageDirectory($pageId));
    }
}
