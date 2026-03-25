<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

use PDO;

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

    public function enforcePersistedSelections(PDO $db, string $imagesTable, int $pageId, string $updatedAt): void
    {
        $read = $db->prepare(
            'SELECT id, sort_order, is_cover
             FROM ' . $imagesTable . '
             WHERE page_id = :page_id
             ORDER BY sort_order ASC, id ASC'
        );
        $read->execute([':page_id' => $pageId]);
        $rows = $read->fetchAll() ?: [];

        if ($rows === []) {
            return;
        }

        $coverWinner = null;
        $updatesById = [];

        foreach ($rows as $row) {
            $imageId = (int) ($row['id'] ?? 0);
            if ($imageId < 1) {
                continue;
            }

            $isCover = (int) ($row['is_cover'] ?? 0) === 1;
            if ($isCover) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $updatesById[$imageId]['is_cover'] = 0;
                }
            }

        }

        if ($updatesById === []) {
            return;
        }

        $updateCover = $db->prepare(
            'UPDATE ' . $imagesTable . '
             SET is_cover = :value,
                 updated_at = :updated_at
             WHERE id = :id
               AND page_id = :page_id'
        );
        foreach ($updatesById as $imageId => $flags) {
            if (array_key_exists('is_cover', $flags)) {
                $updateCover->execute([
                    ':value' => (int) $flags['is_cover'],
                    ':updated_at' => $updatedAt,
                    ':id' => (int) $imageId,
                    ':page_id' => $pageId,
                ]);
            }
        }
    }
}
