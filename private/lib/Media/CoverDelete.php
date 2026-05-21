<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/CoverDelete.php
 * Filesystem deletion helper for user cover image files.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Deletes stored user cover image files.
 *
 * Handles the current per-user directory layout (`uploads/user/{userId}/cover.ext`),
 * external URLs (skipped — nothing local to delete), and the legacy flat-directory
 * format (`uploads/user/cover/{filename}`) used before per-user directories were introduced.
 */
final class CoverDelete
{
    private string $projectRoot;

    /**
     * Prepares the delete helper for the given project root.
     *
     * @param string $projectRoot Absolute project root for filesystem path resolution.
     * @return void
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * Deletes one stored user cover image file.
     *
     * Accepts the relative path returned by CoverUpload::storeForUser, an external
     * URL (skipped — nothing local to delete), or a legacy bare filename from the
     * old flat-directory layout.
     *
     * @param string $storedPath Stored cover path, legacy filename, or external URL from the user row.
     * @return void
     */
    public function deleteFile(string $storedPath): void
    {
        $normalized = trim($storedPath);
        // Ignore blank values and external URLs because no local filesystem delete is required.
        if ($normalized === '' || preg_match('#^https?://#i', $normalized) === 1) {
            return;
        }

        // Path-style values belong to current storage layout under public/.
        if (str_contains($normalized, '/')) {
            // Path-based value: resolve directly from the public root.
            $absolute = $this->projectRoot . '/public/' . ltrim($normalized, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            return;
        }

        // Legacy flat filename: search the old cover directory.
        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $legacyDir = $this->projectRoot . '/public/uploads/user/cover';
        $path = $legacyDir . '/' . $safeName;
        // Keep legacy cleanup for rows written before per-user cover directories.
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
