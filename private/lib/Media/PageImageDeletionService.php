<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

use PDO;

/**
 * Shared transactional page-image and variant delete workflows.
 */
final class PageImageDeletionService
{
    /**
     * @return array{stored_paths: array<int, string>}|null
     */
    public function deleteImageForPage(PDO $db, string $pagesTable, string $imagesTable, string $variantsTable, int $pageId, int $imageId): ?array
    {
        $db->beginTransaction();

        try {
            $readImage = $db->prepare(
                'SELECT stored_path
                 FROM ' . $imagesTable . '
                 WHERE id = :id AND page = :page
                 LIMIT 1'
            );
            $readImage->execute([
                ':id' => $imageId,
                ':page' => $pageId,
            ]);
            $imagePath = $readImage->fetchColumn();

            if ($imagePath === false) {
                $db->rollBack();
                return null;
            }

            $readVariants = $db->prepare(
                'SELECT stored_path FROM ' . $variantsTable . ' WHERE image = :image_id'
            );
            $readVariants->execute([':image_id' => $imageId]);
            $variantRows = $readVariants->fetchAll() ?: [];

            $deleteVariants = $db->prepare(
                'DELETE FROM ' . $variantsTable . ' WHERE image = :image_id'
            );
            $deleteVariants->execute([':image_id' => $imageId]);

            $deleteImage = $db->prepare(
                'DELETE FROM ' . $imagesTable . ' WHERE id = :id AND page = :page'
            );
            $deleteImage->execute([
                ':id' => $imageId,
                ':page' => $pageId,
            ]);

            $clearPrimary = $db->prepare(
                'UPDATE ' . $pagesTable . '
                 SET cover_image = CASE WHEN cover_image = :image_id THEN NULL ELSE cover_image END,
                     preview_image = CASE WHEN preview_image = :image_id THEN NULL ELSE preview_image END
                 WHERE id = :page'
            );
            $clearPrimary->execute([
                ':image_id' => $imageId,
                ':page' => $pageId,
            ]);

            $db->commit();

            $storedPaths = [(string) $imagePath];
            foreach ($variantRows as $variantRow) {
                $storedPaths[] = (string) ($variantRow['stored_path'] ?? '');
            }

            return [
                'stored_paths' => array_values(
                    array_filter($storedPaths, static fn (string $path): bool => $path !== '')
                ),
            ];
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    public function deleteAllForPage(PDO $db, string $pagesTable, string $imagesTable, string $variantsTable, int $pageId): array
    {
        $db->beginTransaction();

        try {
            $readPaths = $db->prepare(
                'SELECT i.stored_path AS image_path, v.stored_path AS variant_path
                 FROM ' . $imagesTable . ' i
                 LEFT JOIN ' . $variantsTable . ' v ON v.image = i.id
                 WHERE i.page = :page'
            );
            $readPaths->execute([':page' => $pageId]);
            $rows = $readPaths->fetchAll() ?: [];

            $imageIdsStmt = $db->prepare(
                'SELECT id FROM ' . $imagesTable . ' WHERE page = :page'
            );
            $imageIdsStmt->execute([':page' => $pageId]);
            $imageIds = array_map(static fn (mixed $value): int => (int) $value, $imageIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if ($imageIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($imageIds), '?'));
                $deleteVariants = $db->prepare(
                    'DELETE FROM ' . $variantsTable . ' WHERE image IN (' . $placeholders . ')'
                );
                $deleteVariants->execute($imageIds);
            }

            $deleteImages = $db->prepare(
                'DELETE FROM ' . $imagesTable . ' WHERE page = :page'
            );
            $deleteImages->execute([':page' => $pageId]);

            $clearPrimary = $db->prepare(
                'UPDATE ' . $pagesTable . '
                 SET cover_image = NULL,
                     preview_image = NULL
                 WHERE id = :page'
            );
            $clearPrimary->execute([':page' => $pageId]);

            $db->commit();

            $paths = [];
            foreach ($rows as $row) {
                $imagePath = (string) ($row['image_path'] ?? '');
                $variantPath = (string) ($row['variant_path'] ?? '');

                if ($imagePath !== '') {
                    $paths[$imagePath] = $imagePath;
                }

                if ($variantPath !== '') {
                    $paths[$variantPath] = $variantPath;
                }
            }

            return array_values($paths);
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}
