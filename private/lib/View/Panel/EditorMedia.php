<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorMedia.php
 * Normalizes page-editor gallery payload rows from image/variant joins, and handles
 * gallery POST payload normalization for the page editor form.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Security\InputSanitizer;

/**
 * Gallery data helpers for the page editor: DB row hydration and POST payload normalization.
 */
final class EditorMedia
{
    private InputSanitizer $input;

    /**
     * Stores the shared input sanitizer used to normalize gallery form payloads.
     *
     * @param InputSanitizer $input Shared request sanitizer for integer, text, and float coercion.
     * @return void
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Extracts a unique sorted integer-id list from one panel POST payload key.
     *
     * @param array<string, mixed> $post Submitted POST payload containing checkbox id arrays.
     * @param string $key POST key that stores one selected-id array.
     * @return array<int> Unique sorted integer ids that survived sanitization.
     */
    public function selectedIdsFromPost(array $post, string $key = 'selected_ids'): array
    {
        /** @var mixed $rawIds */
        $rawIds = $post[$key] ?? [];
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            $parsed = $this->input->int($rawId, 1);
            if ($parsed !== null) {
                $ids[] = $parsed;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Normalizes gallery-image metadata edits from the nested page-editor POST payload.
     *
     * @param mixed $raw Raw value of the gallery_images POST key.
     * @return array<int, array{
     *   alt_text: string,
     *   title_text: string,
     *   caption: string,
     *   credit: string,
     *   license: string,
     *   focal_x: float|null,
     *   focal_y: float|null,
     *   sort_order: int,
     *   is_cover: bool,
     *   include_in_gallery: bool
     * }> Normalized image-update rows keyed by image id and ordered by sanitized sort priority.
     */
    public function normalizeGalleryImageUpdates(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $updates = [];

        foreach ($raw as $rawImageId => $rawData) {
            $imageId = $this->input->int($rawImageId, 1);
            if ($imageId === null || !is_array($rawData)) {
                continue;
            }

            $sortOrder = $this->input->int($rawData['sort_order'] ?? null, 1) ?? 1;
            $sharedAltTitle = $this->input->text($rawData['alt_text'] ?? ($rawData['title_text'] ?? null), 255);

            $updates[$imageId] = [
                'alt_text' => $sharedAltTitle,
                'title_text' => $sharedAltTitle,
                'caption' => $this->input->text($rawData['caption'] ?? null, 2000),
                'credit' => $this->input->text($rawData['credit'] ?? null, 255),
                'license' => $this->input->text($rawData['license'] ?? null, 255),
                'focal_x' => $this->normalizeNullableFloat($rawData['focal_x'] ?? null, 0.0, 100.0),
                'focal_y' => $this->normalizeNullableFloat($rawData['focal_y'] ?? null, 0.0, 100.0),
                'sort_order' => $sortOrder,
                'is_cover' => isset($rawData['is_cover']) && (string) $rawData['is_cover'] === '1',
                'include_in_gallery' => isset($rawData['include_in_gallery']) && (string) $rawData['include_in_gallery'] === '1',
            ];
        }

        ksort($updates);

        if ($updates === []) {
            return [];
        }

        $orderedImageIds = array_keys($updates);
        usort($orderedImageIds, static function (int $a, int $b) use ($updates): int {
            $aSort = (int) ($updates[$a]['sort_order'] ?? 1);
            $bSort = (int) ($updates[$b]['sort_order'] ?? 1);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return $a <=> $b;
        });

        $coverWinner = null;
        foreach ($orderedImageIds as $imageId) {
            if (!empty($updates[$imageId]['is_cover'])) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $updates[$imageId]['is_cover'] = false;
                }
            }
        }

        return $updates;
    }

    // --- Methods below are disabled pending confirmation they are no longer wired up. ---
    // stripEditorMediaColumns() and hydrate() had zero callers as of 2026-05-07.
    // Leaving them commented out rather than deleted to surface any breakage.

    /*
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
    */

    /**
     * Normalizes one optional float field while rejecting blanks and out-of-range values.
     *
     * @param mixed $value Raw numeric form value.
     * @param float $min Lowest allowed value for the field.
     * @param float $max Highest allowed value for the field.
     * @return float|null Sanitized float when the value is valid, otherwise null.
     */
    private function normalizeNullableFloat(mixed $value, float $min, float $max): ?float
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;
        if ($floatValue < $min || $floatValue > $max) {
            return null;
        }

        return $floatValue;
    }
}
