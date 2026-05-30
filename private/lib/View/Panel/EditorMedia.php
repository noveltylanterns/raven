<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorMedia.php
 * Normalizes page-editor gallery payload rows from image/variant joins, and handles
 * gallery POST payload normalization for the page editor form.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Media\MediaUpload;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Upload;

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
        // Selection ids must arrive as an array payload.
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];
        // Parse each candidate id through shared integer sanitizer bounds.
        foreach ($rawIds as $rawId) {
            $parsed = $this->input->int($rawId, 1);
            // Keep only ids that survived integer sanitization.
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
        // Ignore malformed payloads so gallery update handling stays deterministic.
        if (!is_array($raw)) {
            return [];
        }

        $updates = [];

        // Normalize each nested row independently so one bad row does not block valid updates.
        foreach ($raw as $rawImageId => $rawData) {
            $imageId = $this->input->int($rawImageId, 1);
            // Skip rows that do not map to a valid numeric image id and field map.
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

        // Exit early when every row was rejected during normalization.
        if ($updates === []) {
            return [];
        }

        $orderedImageIds = array_keys($updates);
        usort($orderedImageIds, static function (int $a, int $b) use ($updates): int {
            $aSort = (int) ($updates[$a]['sort_order'] ?? 1);
            $bSort = (int) ($updates[$b]['sort_order'] ?? 1);
            // Primary ordering follows user-provided sort order.
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return $a <=> $b;
        });

        $coverWinner = null;
        // Preserve exactly one cover flag by keeping the first cover row in final sort order.
        foreach ($orderedImageIds as $imageId) {
            // Only rows explicitly marked as cover participate in winner selection.
            if (!empty($updates[$imageId]['is_cover'])) {
                // Keep the first cover candidate and demote any later duplicates.
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $updates[$imageId]['is_cover'] = false;
                }
            }
        }

        return $updates;
    }

    /**
     * Normalizes one gallery upload input group into a flat upload list.
     *
     * @param array<string, mixed> $files Full `$_FILES` payload from the page editor request.
     * @param Upload $upload Upload normalizer used to flatten multi-file inputs.
     * @return array<int, array<string, mixed>> Normalized upload rows.
     */
    public function galleryUploadsFromFiles(array $files, Upload $upload): array
    {
        /** @var mixed $rawUploads */
        $rawUploads = $files['gallery_upload_image'] ?? null;
        return $upload->normalize($rawUploads);
    }

    /**
     * Runs one page-gallery upload batch and returns success/error counters.
     *
     * @param MediaUpload $mediaUpload Media upload service for per-file processing.
     * @param int $pageId Target page id for gallery uploads.
     * @param array<int, array<string, mixed>> $uploads Normalized upload rows.
     * @return array{success_count: int, errors: array<int, string>} Batch result payload.
     */
    public function runGalleryUploadBatch(MediaUpload $mediaUpload, int $pageId, array $uploads): array
    {
        $successCount = 0;
        $errors = [];

        // Process uploads individually so one failed file can be surfaced without aborting the batch.
        foreach ($uploads as $upload) {
            $result = $mediaUpload->uploadForPage($pageId, $upload);
            // Successful rows increment the counter and skip error collection.
            if ((bool) ($result['ok'] ?? false)) {
                $successCount++;
                continue;
            }

            $errors[] = (string) ($result['error'] ?? 'Failed to upload one image.');
        }

        return [
            'success_count' => $successCount,
            'errors' => $errors,
        ];
    }

    /**
     * Runs one page-gallery delete batch and returns deleted/failed counters.
     *
     * @param MediaUpload $mediaUpload Media upload service for per-image delete operations.
     * @param int $pageId Target page id for image deletion.
     * @param array<int> $imageIds Selected image ids to delete.
     * @return array{deleted_count: int, failed_count: int} Batch delete counters.
     */
    public function runGalleryDeleteBatch(MediaUpload $mediaUpload, int $pageId, array $imageIds): array
    {
        $deletedCount = 0;
        $failedCount = 0;

        // Track delete successes and failures separately for a complete panel summary message.
        foreach ($imageIds as $imageId) {
            // Count each delete result instead of stopping on the first failure.
            if ($mediaUpload->deleteImageForPage($pageId, $imageId)) {
                $deletedCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'deleted_count' => $deletedCount,
            'failed_count' => $failedCount,
        ];
    }

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
        // Treat blank strings as an intentional "unset" signal from form inputs.
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        // Reject null and non-numeric values before coercing to float.
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;
        // Enforce the configured inclusive range to prevent invalid focal coordinates.
        if ($floatValue < $min || $floatValue > $max) {
            return null;
        }

        return $floatValue;
    }
}
