<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Shared page-image cover single-selection normalization policy.
 */
final class PageImagePrimarySelectionService
{
    /**
     * @param array<int, array<string, scalar|null>> $imageUpdates
     * @return array<int, array<string, scalar|null>>
     */
    public function canonicalizePayloadSelections(array $imageUpdates): array
    {
        if ($imageUpdates === []) {
            return [];
        }

        $orderedImageIds = array_keys($imageUpdates);
        usort($orderedImageIds, static function (int $a, int $b) use ($imageUpdates): int {
            $aSort = (int) ($imageUpdates[$a]['sort_order'] ?? 1);
            $bSort = (int) ($imageUpdates[$b]['sort_order'] ?? 1);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return $a <=> $b;
        });

        $coverWinner = null;
        foreach ($orderedImageIds as $imageId) {
            if (!empty($imageUpdates[$imageId]['is_cover'])) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $imageUpdates[$imageId]['is_cover'] = false;
                }
            }
        }

        return $imageUpdates;
    }

    /**
     * @param array<int, array<string, scalar|null>> $imageUpdates
     */
    public function selectedImageId(array $imageUpdates): ?int
    {
        foreach ($this->canonicalizePayloadSelections($imageUpdates) as $imageId => $imageUpdate) {
            if (!empty($imageUpdate['is_cover'])) {
                return (int) $imageId;
            }
        }

        return null;
    }
}
