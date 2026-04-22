<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/PageImageScribe.php
 * Write-side persistence helper for per-page gallery images and variants.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Lib\Database\TableNameResolver;

/**
 * Owns page-image mutation writes and delete workflows.
 *
 * PageImageRepository keeps the read-heavy gallery listing queries, while this
 * class centralizes image insert/update/delete persistence, cover-selection
 * normalization, and transactional cleanup for page-gallery writes.
 */
final class PageImageScribe
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * Prepares the page-image scribe for page gallery writes.
     *
     * @param PDO    $db     App database connection used for page-image writes.
     * @param string $driver Active PDO driver name used for table resolution and key-return policy.
     * @param string $prefix Application table prefix before resolver sanitization.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Inserts one source image row and all generated variant rows.
     *
     * @param array<string, scalar|null>                  $image    Normalized source-image payload.
     * @param array<int, array<string, scalar|null>>      $variants Normalized generated variant payload rows.
     * @return int Persisted image id.
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function insertImageWithVariants(array $image, array $variants): int
    {
        $images = $this->table('page_images');
        $imageVariants = $this->table('page_image_variants');
        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();

        try {
            if ($this->driver === 'pgsql') {
                // PostgreSQL uses RETURNING for reliable primary-key retrieval.
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $images . ' (
                        page, storage_target, original_filename, stored_filename, stored_path,
                        mime_type, extension, byte_size, width, height, hash,
                        status, sort_order, include_in_gallery, alt_text, title_text, caption, credit, license,
                        focal_x, focal_y, created, updated
                    ) VALUES (
                        :page, :storage_target, :original_filename, :stored_filename, :stored_path,
                        :mime_type, :extension, :byte_size, :width, :height, :hash,
                        :status, :sort_order, :include_in_gallery, :alt_text, :title_text, :caption, :credit, :license,
                        :focal_x, :focal_y, :created, :updated
                    )
                    RETURNING id'
                );
                $insert->execute($this->imageWriteParams($image, $now));

                $imageId = (int) $insert->fetchColumn();
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $images . ' (
                        page, storage_target, original_filename, stored_filename, stored_path,
                        mime_type, extension, byte_size, width, height, hash,
                        status, sort_order, include_in_gallery, alt_text, title_text, caption, credit, license,
                        focal_x, focal_y, created, updated
                    ) VALUES (
                        :page, :storage_target, :original_filename, :stored_filename, :stored_path,
                        :mime_type, :extension, :byte_size, :width, :height, :hash,
                        :status, :sort_order, :include_in_gallery, :alt_text, :title_text, :caption, :credit, :license,
                        :focal_x, :focal_y, :created, :updated
                    )'
                );
                $insert->execute($this->imageWriteParams($image, $now));

                $imageId = (int) $this->db->lastInsertId();
            }

            $insertVariant = $this->db->prepare(
                'INSERT INTO ' . $imageVariants . ' (
                    image, variant_key, stored_filename, stored_path,
                    mime_type, extension, byte_size, width, height, created
                ) VALUES (
                    :image_id, :variant_key, :stored_filename, :stored_path,
                    :mime_type, :extension, :byte_size, :width, :height, :created
                )'
            );

            foreach ($variants as $variant) {
                $insertVariant->execute([
                    ':image_id' => $imageId,
                    ':variant_key' => (string) ($variant['variant_key'] ?? ''),
                    ':stored_filename' => (string) ($variant['stored_filename'] ?? ''),
                    ':stored_path' => (string) ($variant['stored_path'] ?? ''),
                    ':mime_type' => (string) ($variant['mime_type'] ?? ''),
                    ':extension' => (string) ($variant['extension'] ?? ''),
                    ':byte_size' => (int) ($variant['byte_size'] ?? 0),
                    ':width' => (int) ($variant['width'] ?? 0),
                    ':height' => (int) ($variant['height'] ?? 0),
                    ':created' => $now,
                ]);
            }

            $this->db->commit();

            return $imageId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Updates one page's cover image selection and per-image metadata.
     *
     * The `enabled` parameter remains for controller/repository compatibility.
     * The current schema stores no page-level gallery toggle, so this write path
     * only persists cover-image pointers and image-row metadata.
     *
     * @param int                                          $pageId       Page whose gallery metadata is being updated.
     * @param bool                                         $enabled      Unused gallery-enabled flag retained for compatibility.
     * @param array<int, array<string, scalar|null>>       $imageUpdates Per-image metadata and cover selection payload.
     * @return void
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function updateGalleryForPage(int $pageId, bool $enabled, array $imageUpdates): void
    {
        unset($enabled);

        $pages = $this->table('pages');
        $images = $this->table('page_images');
        $now = gmdate('Y-m-d H:i:s');
        $imageUpdates = $this->canonicalizePrimarySelections($imageUpdates);
        $coverImageId = $this->resolvedPrimaryImageId($pageId, $imageUpdates);

        $this->db->beginTransaction();

        try {
            $updatePage = $this->db->prepare(
                'UPDATE ' . $pages . '
                 SET cover_image = :cover_image,
                     preview_image = :preview_image,
                     updated = :updated
                 WHERE id = :id'
            );
            $updatePage->execute([
                ':cover_image' => $coverImageId,
                ':preview_image' => $coverImageId,
                ':updated' => $now,
                ':id' => $pageId,
            ]);

            if ($imageUpdates !== []) {
                $updateImage = $this->db->prepare(
                    'UPDATE ' . $images . '
                     SET alt_text = :alt_text,
                         title_text = :title_text,
                         caption = :caption,
                         credit = :credit,
                         license = :license,
                         focal_x = :focal_x,
                         focal_y = :focal_y,
                         sort_order = :sort_order,
                         include_in_gallery = :include_in_gallery,
                         updated = :updated
                     WHERE id = :id
                       AND page = :page'
                );

                foreach ($imageUpdates as $imageId => $update) {
                    $updateImage->execute([
                        ':alt_text' => (string) ($update['alt_text'] ?? ''),
                        ':title_text' => (string) ($update['title_text'] ?? ''),
                        ':caption' => (string) ($update['caption'] ?? ''),
                        ':credit' => (string) ($update['credit'] ?? ''),
                        ':license' => (string) ($update['license'] ?? ''),
                        ':focal_x' => $update['focal_x'] ?? null,
                        ':focal_y' => $update['focal_y'] ?? null,
                        ':sort_order' => (int) ($update['sort_order'] ?? 1),
                        ':include_in_gallery' => array_key_exists('include_in_gallery', $update) && empty($update['include_in_gallery']) ? 0 : 1,
                        ':updated' => $now,
                        ':id' => (int) $imageId,
                        ':page' => $pageId,
                    ]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes one gallery image and its variants, returning all stored file paths.
     *
     * @param int $pageId  Owning page id.
     * @param int $imageId Image id to delete.
     * @return array{stored_paths: array<int, string>}|null File paths for filesystem cleanup, or null when the image was not found on the page.
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function deleteImageForPage(int $pageId, int $imageId): ?array
    {
        $pagesTable = $this->table('pages');
        $imagesTable = $this->table('page_images');
        $variantsTable = $this->table('page_image_variants');

        $this->db->beginTransaction();

        try {
            $readImage = $this->db->prepare(
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
                $this->db->rollBack();

                return null;
            }

            $readVariants = $this->db->prepare(
                'SELECT stored_path FROM ' . $variantsTable . ' WHERE image = :image_id'
            );
            $readVariants->execute([':image_id' => $imageId]);
            $variantRows = $readVariants->fetchAll() ?: [];

            $deleteVariants = $this->db->prepare(
                'DELETE FROM ' . $variantsTable . ' WHERE image = :image_id'
            );
            $deleteVariants->execute([':image_id' => $imageId]);

            $deleteImage = $this->db->prepare(
                'DELETE FROM ' . $imagesTable . ' WHERE id = :id AND page = :page'
            );
            $deleteImage->execute([
                ':id' => $imageId,
                ':page' => $pageId,
            ]);

            $clearPrimary = $this->db->prepare(
                'UPDATE ' . $pagesTable . '
                 SET cover_image = CASE WHEN cover_image = :image_id THEN NULL ELSE cover_image END,
                     preview_image = CASE WHEN preview_image = :image_id THEN NULL ELSE preview_image END
                 WHERE id = :page'
            );
            $clearPrimary->execute([
                ':image_id' => $imageId,
                ':page' => $pageId,
            ]);

            $this->db->commit();

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
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes all gallery rows for one page and returns every stored file path.
     *
     * @param int $pageId Page whose gallery rows are being deleted.
     * @return array<int, string> Unique stored paths for filesystem cleanup after the transaction commits.
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function deleteAllForPage(int $pageId): array
    {
        $pagesTable = $this->table('pages');
        $imagesTable = $this->table('page_images');
        $variantsTable = $this->table('page_image_variants');

        $this->db->beginTransaction();

        try {
            $readPaths = $this->db->prepare(
                'SELECT i.stored_path AS image_path, v.stored_path AS variant_path
                 FROM ' . $imagesTable . ' i
                 LEFT JOIN ' . $variantsTable . ' v ON v.image = i.id
                 WHERE i.page = :page'
            );
            $readPaths->execute([':page' => $pageId]);
            $rows = $readPaths->fetchAll() ?: [];

            $imageIdsStmt = $this->db->prepare(
                'SELECT id FROM ' . $imagesTable . ' WHERE page = :page'
            );
            $imageIdsStmt->execute([':page' => $pageId]);
            $imageIds = array_map(static fn (mixed $value): int => (int) $value, $imageIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if ($imageIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($imageIds), '?'));
                $deleteVariants = $this->db->prepare(
                    'DELETE FROM ' . $variantsTable . ' WHERE image IN (' . $placeholders . ')'
                );
                $deleteVariants->execute($imageIds);
            }

            $deleteImages = $this->db->prepare(
                'DELETE FROM ' . $imagesTable . ' WHERE page = :page'
            );
            $deleteImages->execute([':page' => $pageId]);

            $clearPrimary = $this->db->prepare(
                'UPDATE ' . $pagesTable . '
                 SET cover_image = NULL,
                     preview_image = NULL
                 WHERE id = :page'
            );
            $clearPrimary->execute([':page' => $pageId]);

            $this->db->commit();

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
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Normalizes source-image insert parameters to the repository's stored schema.
     *
     * @param array<string, scalar|null> $image Normalized source-image payload.
     * @param string                     $now   Shared timestamp for created/updated columns.
     * @return array<string, float|int|string|null> Prepared-statement parameter map.
     */
    private function imageWriteParams(array $image, string $now): array
    {
        return [
            ':page' => (int) ($image['page'] ?? 0),
            ':storage_target' => (string) ($image['storage_target'] ?? 'local'),
            ':original_filename' => (string) ($image['original_filename'] ?? ''),
            ':stored_filename' => (string) ($image['stored_filename'] ?? ''),
            ':stored_path' => (string) ($image['stored_path'] ?? ''),
            ':mime_type' => (string) ($image['mime_type'] ?? ''),
            ':extension' => (string) ($image['extension'] ?? ''),
            ':byte_size' => (int) ($image['byte_size'] ?? 0),
            ':width' => (int) ($image['width'] ?? 0),
            ':height' => (int) ($image['height'] ?? 0),
            ':hash' => (string) ($image['hash'] ?? ''),
            ':status' => (string) ($image['status'] ?? 'ready'),
            ':sort_order' => (int) ($image['sort_order'] ?? 1),
            ':include_in_gallery' => array_key_exists('include_in_gallery', $image) && empty($image['include_in_gallery']) ? 0 : 1,
            ':alt_text' => (string) ($image['alt_text'] ?? ''),
            ':title_text' => (string) ($image['title_text'] ?? ''),
            ':caption' => (string) ($image['caption'] ?? ''),
            ':credit' => (string) ($image['credit'] ?? ''),
            ':license' => (string) ($image['license'] ?? ''),
            ':focal_x' => $image['focal_x'] === null ? null : (float) $image['focal_x'],
            ':focal_y' => $image['focal_y'] === null ? null : (float) $image['focal_y'],
            ':created' => $now,
            ':updated' => $now,
        ];
    }

    /**
     * Enforces one cover selection across a page update payload.
     *
     * @param array<int, array<string, scalar|null>> $imageUpdates Per-image metadata keyed by image id.
     * @return array<int, array<string, scalar|null>> Canonicalized payload with at most one cover image selected.
     */
    private function canonicalizePrimarySelections(array $imageUpdates): array
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
     * Resolves the primary image id that should be stored on the page row.
     *
     * @param int                                    $pageId       Owning page id.
     * @param array<int, array<string, scalar|null>> $imageUpdates Canonicalized page-image update payload.
     * @return int|null Selected cover image id, existing cover image id, or null when none applies.
     */
    private function resolvedPrimaryImageId(int $pageId, array $imageUpdates): ?int
    {
        foreach ($this->canonicalizePrimarySelections($imageUpdates) as $imageId => $imageUpdate) {
            if (!empty($imageUpdate['is_cover'])) {
                return (int) $imageId;
            }
        }

        $pages = $this->table('pages');
        $images = $this->table('page_images');
        $stmt = $this->db->prepare(
            'SELECT p.cover_image
             FROM ' . $pages . ' p
             WHERE p.id = :id
               AND (
                    p.cover_image IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM ' . $images . ' i
                        WHERE i.id = p.cover_image
                          AND i.page = p.id
                    )
               )
             LIMIT 1'
        );
        $stmt->execute([':id' => $pageId]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        $imageId = (int) $value;

        return $imageId > 0 ? $imageId : null;
    }

    /**
     * Maps one logical table name to its physical name for the current driver.
     *
     * @param string $table Logical application table name.
     * @return string Physical prefixed table name.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
