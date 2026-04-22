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
 */
final class UserMediaPathService
{
    /**
     * Builds panel/public template data for one stored avatar filename.
     *
     * @param string $projectRoot Project root path retained for compatibility with older call sites.
     * @param string $avatarPath Stored avatar filename or path from the user row.
     * @return array{filename: string, url: string, thumb_url: string}
     */
    public function avatarTemplateData(string $projectRoot, string $avatarPath): array
    {
        unset($projectRoot);

        $avatarFilename = basename(trim($avatarPath));
        if ($avatarFilename === '') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
        $avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;

        return [
            'filename' => $avatarFilename,
            'url' => '/uploads/avatars/' . rawurlencode($avatarFilename),
            'thumb_url' => '/uploads/avatars/' . rawurlencode($avatarThumbFilename),
        ];
    }

    /**
     * Resolves one stored cover-image value to the public URL used by views.
     *
     * @param string $projectRoot Project root path retained for compatibility with older call sites.
     * @param string $coverValue Stored cover-image filename or URL from the user row.
     * @return string Public URL or the original absolute URL.
     */
    public function coverPublicUrl(string $projectRoot, string $coverValue): string
    {
        unset($projectRoot);

        $normalized = trim($coverValue);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $normalized) === 1 || str_starts_with($normalized, '/')) {
            return $normalized;
        }

        $filename = basename($normalized);
        return '/uploads/user/cover/' . rawurlencode($filename);
    }

    /**
     * Builds the thumbnail filename that matches the avatar upload sanitizer.
     *
     * @param string $filename Stored avatar filename.
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
