<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/Panel/UserMediaPathService.php
 * Resolves user-media storage paths and public URLs for avatar and cover images.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media\Panel;

/**
 * Resolves user-media storage paths and public URLs for avatar and cover images.
 *
 * Avatar and cover images are stored under `uploads/user/{userId}/` with fixed
 * base filenames (`avatar.ext`, `cover.ext`). This service reads those stored
 * relative paths and converts them to public URLs for panel and theme templates.
 */
final class UserMediaPathService
{
    /**
     * Builds panel/public template data for one stored avatar path.
     *
     * Stored path format is `uploads/user/{uid}/avatar.ext`. The public URL is
     * `/uploads/user/{uid}/avatar.ext` and the thumbnail URL is the sibling
     * `avatar_thumb.jpg` in the same per-user directory.
     *
     * @param string $projectRoot Retained for legacy call-site compatibility; not used.
     * @param string $avatarPath Stored avatar relative path from the user row.
     * @return array{filename: string, url: string, thumb_url: string}
     */
    public function avatarTemplateData(string $projectRoot, string $avatarPath): array
    {
        unset($projectRoot);

        $normalized = trim($avatarPath);
        if ($normalized === '') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $filename = basename($normalized);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        if (str_contains($normalized, '/')) {
            // New per-user path format: uploads/user/{uid}/avatar.ext
            $dir = ltrim(dirname($normalized), '/');
            $base = (string) pathinfo($filename, PATHINFO_FILENAME);
            $thumbFilename = ($base !== '' ? $base : 'avatar') . '_thumb.jpg';

            return [
                'filename' => $filename,
                'url'       => '/' . $dir . '/' . rawurlencode($filename),
                'thumb_url' => '/' . $dir . '/' . rawurlencode($thumbFilename),
            ];
        }

        // Legacy flat filename: map to the old avatars flat directory.
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        $thumbFilename = ($base !== '' ? $base : 'avatar') . '_thumb.jpg';

        return [
            'filename'  => $filename,
            'url'       => '/uploads/avatars/' . rawurlencode($filename),
            'thumb_url' => '/uploads/avatars/' . rawurlencode($thumbFilename),
        ];
    }

    /**
     * Resolves one stored cover-image value to the public URL used by views.
     *
     * Stored path format is `uploads/user/{uid}/cover.ext`, which maps directly
     * to `/uploads/user/{uid}/cover.ext`. Absolute URLs (http/https) are returned
     * as-is to support externally hosted cover images.
     *
     * @param string $projectRoot Retained for legacy call-site compatibility; not used.
     * @param string $coverValue Stored cover-image path or external URL from the user row.
     * @return string Public URL, or empty string when the value is blank.
     */
    public function coverPublicUrl(string $projectRoot, string $coverValue): string
    {
        unset($projectRoot);

        $normalized = trim($coverValue);
        if ($normalized === '') {
            return '';
        }

        // Absolute URLs and /uploads/... legacy paths are returned as-is.
        if (preg_match('#^https?://#i', $normalized) === 1 || str_starts_with($normalized, '/')) {
            return $normalized;
        }

        // Both new per-user format (uploads/user/{uid}/cover.ext) and legacy flat
        // filename (abc123.jpg) can be mapped by prepending a slash — the old flat
        // directory path is stored as a relative path already when written by the new
        // scribe, and a bare filename falls back to the old /uploads/user/cover/ bucket.
        if (str_contains($normalized, '/')) {
            return '/' . $normalized;
        }

        // Legacy bare filename: map to old flat cover directory.
        return '/uploads/user/cover/' . rawurlencode(basename($normalized));
    }

    /**
     * Builds the thumbnail filename that matches the avatar upload sanitizer output.
     *
     * @param string $filename Stored avatar filename (basename only).
     * @return string Thumbnail filename for the same avatar.
     */
    public function thumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }
}
