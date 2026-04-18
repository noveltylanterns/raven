<?php

declare(strict_types=1);

namespace Raven\Lib\Media\Panel;

/**
 * Shared page gallery storage path layout and cleanup helpers.
 */
final class PageImagePathLayout
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    public function pageDirectory(int $pageId): string
    {
        return $this->projectRoot . '/public/uploads/pages/' . $pageId;
    }

    public function ensurePageDirectory(int $pageId): bool
    {
        $directory = $this->pageDirectory($pageId);
        return is_dir($directory) || (mkdir($directory, 0775, true) && is_dir($directory));
    }

    public function storedPathForFilename(int $pageId, string $filename): string
    {
        return 'uploads/pages/' . $pageId . '/' . ltrim($filename, '/');
    }

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

        if ($normalized === '' || str_contains($normalized, '..')) {
            return;
        }

        if (!str_starts_with($normalized, 'uploads/pages/')) {
            return;
        }

        $absolutePath = $this->absolutePublicPath($normalized);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * Removes now-empty page directory after image deletion.
     */
    public function removePageDirectoryIfEmpty(int $pageId): void
    {
        $directory = $this->pageDirectory($pageId);
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Directory still has files/subdirs, keep it.
            return;
        }

        @rmdir($directory);
    }
}

