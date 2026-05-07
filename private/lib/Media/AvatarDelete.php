<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/AvatarDelete.php
 * Filesystem deletion helper for user avatar files and thumbnails.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Deletes stored user avatar files and their thumbnail derivatives.
 *
 * Handles both the current per-user directory layout (`uploads/user/{userId}/avatar.ext`)
 * and the legacy flat-directory format (`uploads/avatars/{filename}`) used before
 * per-user directories were introduced, so old rows still clean up correctly.
 */
final class AvatarDelete
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
     * Deletes one stored avatar file and its thumbnail derivative.
     *
     * Accepts the relative path returned by AvatarUpload::storeForUser (new format:
     * `uploads/user/{uid}/avatar.ext`) or a legacy bare filename (old flat-directory
     * format) so that rows written before the per-user directory layout still clean up.
     *
     * @param string $storedPath Stored avatar path or legacy filename from the user row.
     * @return void
     */
    public function deleteFile(string $storedPath): void
    {
        $normalized = trim($storedPath);
        if ($normalized === '') {
            return;
        }

        if (str_contains($normalized, '/')) {
            // New per-user path: uploads/user/{uid}/avatar.ext — resolve from public root.
            $absolute = $this->projectRoot . '/public/' . ltrim($normalized, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            // Thumbnail lives alongside the source file with a _thumb.jpg suffix.
            $thumbAbsolute = dirname($absolute) . '/' . $this->thumbnailFilename(basename($absolute));
            if (is_file($thumbAbsolute)) {
                @unlink($thumbAbsolute);
            }

            return;
        }

        // Legacy flat filename: search the old avatars directory.
        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $legacyDir = $this->projectRoot . '/public/uploads/avatars';
        $path = $legacyDir . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }

        $thumbPath = $legacyDir . '/' . $this->thumbnailFilename($safeName);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
    }

    /**
     * Builds the deterministic thumbnail filename from a source avatar filename.
     *
     * Mirrors the convention used by AvatarUpload when writing thumbnail derivatives,
     * so delete and write always agree on the filename.
     *
     * @param string $filename Source avatar filename.
     * @return string Thumbnail filename with _thumb.jpg suffix.
     */
    private function thumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }
}
