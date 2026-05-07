<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/MediaWrite.php
 * Write-side data access for page-scoped media rows and their size variants.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;

/**
 * INSERT, UPDATE, and DELETE methods for page-scoped media rows and size variants.
 *
 * Read operations (SELECT, lookup) live in MediaRead.
 */
final class MediaWrite
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
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
     * @param array<string, scalar|null> $image    Image metadata fields for the source row.
     * @param array<int, array<string, scalar|null>> $variants Variant rows keyed by sequential index.
     * @return int The inserted image id.
     */
    public function insertImageWithVariants(array $image, array $variants): int
    {
        $images = $this->table('media');
        $imageVariants = $this->table('media_variants');
        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();

        try {
            if ($this->driver === 'pgsql') {
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
     * Updates one page's cover-image selection and per-image metadata.
     *
     * @param int                                    $pageId       Page whose gallery metadata to update.
     * @param array<int, array<string, scalar|null>> $imageUpdates Per-image metadata fields keyed by sequential index.
     * @return void
     */
    public function updateGalleryForPage(int $pageId, array $imageUpdates): void
    {
        $pages = $this->table('pages');
        $images = $this->table('media');
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
     * Deletes one gallery image and all its variant rows, returning the stored file paths.
     *
     * @param int $pageId  Page that owns the image (used as a safety scope check).
     * @param int $imageId Image id to delete.
     * @return array{stored_paths: array<int, string>}|null Stored file paths for cleanup, or null when not found.
     */
    public function deleteImageForPage(int $pageId, int $imageId): ?array
    {
        $pagesTable = $this->table('pages');
        $imagesTable = $this->table('media');
        $variantsTable = $this->table('media_variants');

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
     * Deletes all gallery rows for one page and returns all stored file paths.
     *
     * @param int $pageId Page whose images to delete.
     * @return array<int, string> All stored file paths that should be removed from storage.
     */
    public function deleteAllForPage(int $pageId): array
    {
        $pagesTable = $this->table('pages');
        $imagesTable = $this->table('media');
        $variantsTable = $this->table('media_variants');

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
     * @param array<string, scalar|null> $image
     * @param string $now
     * @return array<string, scalar|null>
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
            ':focal_x' => $image['focal_x'] ?? null,
            ':focal_y' => $image['focal_y'] ?? null,
            ':created' => $now,
            ':updated' => $now,
        ];
    }

    /**
     * @param array<int, array<string, scalar|null>> $imageUpdates
     * @return array<int, array<string, scalar|null>>
     */
    private function canonicalizePrimarySelections(array $imageUpdates): array
    {
        $resolved = [];
        $primaryId = null;

        foreach ($imageUpdates as $imageId => $update) {
            $normalizedId = (int) $imageId;
            if ($normalizedId < 1) {
                continue;
            }

            if (!is_array($update)) {
                $update = [];
            }

            $isPrimary = !empty($update['is_primary']) || !empty($update['is_cover']) || !empty($update['is_preview']);
            if ($isPrimary && $primaryId === null) {
                $primaryId = $normalizedId;
            }

            $update['is_primary'] = $isPrimary ? 1 : 0;
            unset($update['is_cover'], $update['is_preview']);
            $resolved[$normalizedId] = $update;
        }

        if ($primaryId !== null) {
            foreach ($resolved as $imageId => $update) {
                $update['is_primary'] = $imageId === $primaryId ? 1 : 0;
                $resolved[$imageId] = $update;
            }
        }

        return $resolved;
    }

    /**
     * @param int $pageId
     * @param array<int, array<string, scalar|null>> $imageUpdates
     * @return int|null
     */
    private function resolvedPrimaryImageId(int $pageId, array $imageUpdates): ?int
    {
        foreach ($imageUpdates as $imageId => $update) {
            if (!empty($update['is_primary'])) {
                return (int) $imageId;
            }
        }

        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $this->table('media') . '
             WHERE page = :page
             ORDER BY sort_order ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([':page' => $pageId]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param string $table
     * @return string
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
