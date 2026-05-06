<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/CoverUpload.php
 * Cover-image upload record payload helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Builds storage record payloads for cover-image uploads.
 */
final class CoverUpload
{
    /**
     * Builds persisted cover-image storage columns for one entity update.
     *
     * @param string $entityType Entity type such as categories/channels/groups/tags.
     * @param string $filename Stored source filename.
     * @param array<string, string> $paths Stored source+variant paths.
     * @return array<string, string|null>
     */
    public function recordPayload(string $entityType, string $filename, array $paths): array
    {
        if (PreviewConfig::supportsFilenameStorage($entityType)) {
            return ['cover_image' => $filename];
        }

        return [
            'cover_image_path' => $paths['cover_image_path'] ?? null,
            'cover_image_sm_path' => $paths['cover_image_sm_path'] ?? null,
            'cover_image_md_path' => $paths['cover_image_md_path'] ?? null,
            'cover_image_lg_path' => $paths['cover_image_lg_path'] ?? null,
        ];
    }
}
