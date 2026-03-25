<?php

declare(strict_types=1);

namespace Raven\Lib\Http;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared panel POST payload normalization helpers for bulk ids and gallery metadata.
 */
final class PanelPostNormalizer
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<int>
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
     * @param mixed $raw
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
     * }>
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
