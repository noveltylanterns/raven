<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorMedia.php
 * Normalizes page-editor gallery payload rows from image/variant joins.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Normalizes combined page-image/variant result rows for page editor payloads.
 */
final class EditorMedia
{
    /**
     * Drops image/variant join columns from a page editor row payload.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function stripEditorMediaColumns(array $row): array
    {
        foreach (array_keys($row) as $column) {
            $name = (string) $column;
            if (str_starts_with($name, 'image_') || str_starts_with($name, 'variant_')) {
                unset($row[$name]);
            }
        }

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param callable(string): string $publicUrlFromStoredPath
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $rows, callable $publicUrlFromStoredPath): array
    {
        if ($rows === []) {
            return [];
        }

        $imagesById = [];
        $orderedImageIds = [];
        foreach ($rows as $row) {
            $imageId = (int) ($row['image_id'] ?? 0);
            if ($imageId < 1) {
                continue;
            }

            if (!isset($imagesById[$imageId])) {
                $storedPath = (string) ($row['image_stored_path'] ?? '');
                $imagesById[$imageId] = [
                    'id' => $imageId,
                    'page_id' => (int) ($row['image_page_id'] ?? 0),
                    'storage_target' => (string) ($row['image_storage_target'] ?? ''),
                    'original_filename' => (string) ($row['image_original_filename'] ?? ''),
                    'stored_filename' => (string) ($row['image_stored_filename'] ?? ''),
                    'stored_path' => $storedPath,
                    'url' => $publicUrlFromStoredPath($storedPath),
                    'mime_type' => (string) ($row['image_mime_type'] ?? ''),
                    'extension' => (string) ($row['image_extension'] ?? ''),
                    'byte_size' => (int) ($row['image_byte_size'] ?? 0),
                    'width' => (int) ($row['image_width'] ?? 0),
                    'height' => (int) ($row['image_height'] ?? 0),
                    'hash_sha256' => (string) ($row['image_hash_sha256'] ?? ''),
                    'status' => (string) ($row['image_status'] ?? ''),
                    'sort_order' => (int) ($row['image_sort_order'] ?? 0),
                    'is_cover' => (int) ($row['image_is_cover'] ?? 0) === 1,
                    'include_in_gallery' => (int) ($row['image_include_in_gallery'] ?? 1) === 1,
                    'alt_text' => (string) ($row['image_alt_text'] ?? ''),
                    'title_text' => (string) ($row['image_title_text'] ?? ''),
                    'caption' => (string) ($row['image_caption'] ?? ''),
                    'credit' => (string) ($row['image_credit'] ?? ''),
                    'license' => (string) ($row['image_license'] ?? ''),
                    'focal_x' => $row['image_focal_x'] === null ? null : (float) $row['image_focal_x'],
                    'focal_y' => $row['image_focal_y'] === null ? null : (float) $row['image_focal_y'],
                    'created' => (string) ($row['image_created'] ?? ''),
                    'updated' => (string) ($row['image_updated'] ?? ''),
                    'variants' => [],
                ];
                $orderedImageIds[] = $imageId;
            }

            $variantKey = trim((string) ($row['variant_key'] ?? ''));
            if ($variantKey === '') {
                continue;
            }

            $variantStoredPath = (string) ($row['variant_stored_path'] ?? '');
            $imagesById[$imageId]['variants'][$variantKey] = [
                'variant_key' => $variantKey,
                'stored_filename' => (string) ($row['variant_stored_filename'] ?? ''),
                'stored_path' => $variantStoredPath,
                'url' => $publicUrlFromStoredPath($variantStoredPath),
                'mime_type' => (string) ($row['variant_mime_type'] ?? ''),
                'extension' => (string) ($row['variant_extension'] ?? ''),
                'byte_size' => (int) ($row['variant_byte_size'] ?? 0),
                'width' => (int) ($row['variant_width'] ?? 0),
                'height' => (int) ($row['variant_height'] ?? 0),
            ];
        }

        $result = [];
        foreach ($orderedImageIds as $imageId) {
            $result[] = $imagesById[$imageId];
        }

        return $result;
    }
}
