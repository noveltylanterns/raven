<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/PreviewUpload.php
 * Preview/icon upload record payload helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Builds storage record payloads for preview/icon uploads.
 */
final class PreviewUpload
{
    /** @var string Upload slot (`preview` or `icon`). */
    private string $slot;

    /**
     * Normalizes and stores the active preview slot for this upload helper.
     *
     * @param string $slot Upload slot name.
     * @return void
     */
    public function __construct(string $slot)
    {
        $normalized = strtolower(trim($slot));
        $this->slot = in_array($normalized, ['preview', 'icon'], true) ? $normalized : 'preview';
    }

    /**
     * Builds persisted preview/icon storage columns for one entity update.
     *
     * @param string $entityType Entity type such as categories/channels/groups/tags.
     * @param string $filename Stored source filename.
     * @param array<string, string> $paths Stored source+variant paths.
     * @return array<string, string|null>
     */
    public function recordPayload(string $entityType, string $filename, array $paths): array
    {
        if (PreviewConfig::supportsFilenameStorage($entityType)) {
            return [$this->slot . '_image' => $filename];
        }

        return [
            $this->slot . '_image_path' => $paths[$this->slot . '_image_path'] ?? null,
            $this->slot . '_image_sm_path' => $paths[$this->slot . '_image_sm_path'] ?? null,
            $this->slot . '_image_md_path' => $paths[$this->slot . '_image_md_path'] ?? null,
            $this->slot . '_image_lg_path' => $paths[$this->slot . '_image_lg_path'] ?? null,
        ];
    }
}
