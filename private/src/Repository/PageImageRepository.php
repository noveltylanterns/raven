<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/PageImageRepository.php
 * Repository for per-page gallery images and generated variants.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use PDO;

/**
 * Data access for page gallery images and their size variants.
 */
final class PageImageRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is only used in shared-db modes; SQLite uses attached DB names instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
    }

    /**
     * Returns true when one page id exists.
     */
    public function pageExists(int $pageId): bool
    {
        $pages = $this->table('pages');

        $stmt = $this->db->prepare('SELECT 1 FROM ' . $pages . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pageId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns one page's gallery-enabled flag.
     */
    public function isGalleryEnabledForPage(int $pageId): bool
    {
        $pages = $this->table('pages');

        $stmt = $this->db->prepare('SELECT gallery_enabled FROM ' . $pages . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pageId]);

        $value = $stmt->fetchColumn();

        return $value !== false && (int) $value === 1;
    }

    /**
     * Returns the next sort order value for one page's image list.
     */
    public function nextSortOrderForPage(int $pageId): int
    {
        $images = $this->table('page_images');

        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . $images . ' WHERE page_id = :page_id'
        );
        $stmt->execute([':page_id' => $pageId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Returns true when one page already has an image with the same hash.
     */
    public function hasHashForPage(int $pageId, string $sha256): bool
    {
        $images = $this->table('page_images');

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM ' . $images . '
             WHERE page_id = :page_id
               AND hash_sha256 = :hash_sha256
             LIMIT 1'
        );
        $stmt->execute([
            ':page_id' => $pageId,
            ':hash_sha256' => $sha256,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Inserts one source image row and all generated variant rows.
     *
     * @param array<string, scalar|null> $image
     * @param array<int, array<string, scalar|null>> $variants
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
                        page_id, storage_target, original_filename, stored_filename, stored_path,
                        mime_type, extension, byte_size, width, height, hash_sha256,
                        status, sort_order, is_cover, is_preview, include_in_gallery, alt_text, title_text, caption, credit, license,
                        focal_x, focal_y, created_at, updated_at
                    ) VALUES (
                        :page_id, :storage_target, :original_filename, :stored_filename, :stored_path,
                        :mime_type, :extension, :byte_size, :width, :height, :hash_sha256,
                        :status, :sort_order, :is_cover, :is_preview, :include_in_gallery, :alt_text, :title_text, :caption, :credit, :license,
                        :focal_x, :focal_y, :created_at, :updated_at
                    )
                    RETURNING id'
                );
                $insert->execute([
                    ':page_id' => (int) ($image['page_id'] ?? 0),
                    ':storage_target' => (string) ($image['storage_target'] ?? 'local'),
                    ':original_filename' => (string) ($image['original_filename'] ?? ''),
                    ':stored_filename' => (string) ($image['stored_filename'] ?? ''),
                    ':stored_path' => (string) ($image['stored_path'] ?? ''),
                    ':mime_type' => (string) ($image['mime_type'] ?? ''),
                    ':extension' => (string) ($image['extension'] ?? ''),
                    ':byte_size' => (int) ($image['byte_size'] ?? 0),
                    ':width' => (int) ($image['width'] ?? 0),
                    ':height' => (int) ($image['height'] ?? 0),
                    ':hash_sha256' => (string) ($image['hash_sha256'] ?? ''),
                    ':status' => (string) ($image['status'] ?? 'ready'),
                    ':sort_order' => (int) ($image['sort_order'] ?? 1),
                    ':is_cover' => !empty($image['is_cover']) ? 1 : 0,
                    ':is_preview' => !empty($image['is_preview']) ? 1 : 0,
                    ':include_in_gallery' => array_key_exists('include_in_gallery', $image) && empty($image['include_in_gallery']) ? 0 : 1,
                    ':alt_text' => (string) ($image['alt_text'] ?? ''),
                    ':title_text' => (string) ($image['title_text'] ?? ''),
                    ':caption' => (string) ($image['caption'] ?? ''),
                    ':credit' => (string) ($image['credit'] ?? ''),
                    ':license' => (string) ($image['license'] ?? ''),
                    ':focal_x' => $image['focal_x'] === null ? null : (float) $image['focal_x'],
                    ':focal_y' => $image['focal_y'] === null ? null : (float) $image['focal_y'],
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $imageId = (int) $insert->fetchColumn();
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $images . ' (
                        page_id, storage_target, original_filename, stored_filename, stored_path,
                        mime_type, extension, byte_size, width, height, hash_sha256,
                        status, sort_order, is_cover, is_preview, include_in_gallery, alt_text, title_text, caption, credit, license,
                        focal_x, focal_y, created_at, updated_at
                    ) VALUES (
                        :page_id, :storage_target, :original_filename, :stored_filename, :stored_path,
                        :mime_type, :extension, :byte_size, :width, :height, :hash_sha256,
                        :status, :sort_order, :is_cover, :is_preview, :include_in_gallery, :alt_text, :title_text, :caption, :credit, :license,
                        :focal_x, :focal_y, :created_at, :updated_at
                    )'
                );
                $insert->execute([
                    ':page_id' => (int) ($image['page_id'] ?? 0),
                    ':storage_target' => (string) ($image['storage_target'] ?? 'local'),
                    ':original_filename' => (string) ($image['original_filename'] ?? ''),
                    ':stored_filename' => (string) ($image['stored_filename'] ?? ''),
                    ':stored_path' => (string) ($image['stored_path'] ?? ''),
                    ':mime_type' => (string) ($image['mime_type'] ?? ''),
                    ':extension' => (string) ($image['extension'] ?? ''),
                    ':byte_size' => (int) ($image['byte_size'] ?? 0),
                    ':width' => (int) ($image['width'] ?? 0),
                    ':height' => (int) ($image['height'] ?? 0),
                    ':hash_sha256' => (string) ($image['hash_sha256'] ?? ''),
                    ':status' => (string) ($image['status'] ?? 'ready'),
                    ':sort_order' => (int) ($image['sort_order'] ?? 1),
                    ':is_cover' => !empty($image['is_cover']) ? 1 : 0,
                    ':is_preview' => !empty($image['is_preview']) ? 1 : 0,
                    ':include_in_gallery' => array_key_exists('include_in_gallery', $image) && empty($image['include_in_gallery']) ? 0 : 1,
                    ':alt_text' => (string) ($image['alt_text'] ?? ''),
                    ':title_text' => (string) ($image['title_text'] ?? ''),
                    ':caption' => (string) ($image['caption'] ?? ''),
                    ':credit' => (string) ($image['credit'] ?? ''),
                    ':license' => (string) ($image['license'] ?? ''),
                    ':focal_x' => $image['focal_x'] === null ? null : (float) $image['focal_x'],
                    ':focal_y' => $image['focal_y'] === null ? null : (float) $image['focal_y'],
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $imageId = (int) $this->db->lastInsertId();
            }

            $insertVariant = $this->db->prepare(
                'INSERT INTO ' . $imageVariants . ' (
                    image_id, variant_key, stored_filename, stored_path,
                    mime_type, extension, byte_size, width, height, created_at
                ) VALUES (
                    :image_id, :variant_key, :stored_filename, :stored_path,
                    :mime_type, :extension, :byte_size, :width, :height, :created_at
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
                    ':created_at' => $now,
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
     * Returns all images + variants for one page, sorted for panel editing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPage(int $pageId): array
    {
        $images = $this->table('page_images');
        $variants = $this->table('page_image_variants');

        $stmt = $this->db->prepare(
            'SELECT
                i.id,
                i.page_id,
                i.storage_target,
                i.original_filename,
                i.stored_filename,
                i.stored_path AS image_stored_path,
                i.mime_type,
                i.extension,
                i.byte_size,
                i.width,
                i.height,
                i.hash_sha256,
                i.status,
                i.sort_order,
                i.is_cover,
                i.is_preview,
                i.include_in_gallery,
                i.alt_text,
                i.title_text,
                i.caption,
                i.credit,
                i.license,
                i.focal_x,
                i.focal_y,
                i.created_at,
                i.updated_at,
                v.variant_key,
                v.stored_filename AS variant_stored_filename,
                v.stored_path AS variant_stored_path,
                v.mime_type AS variant_mime_type,
                v.extension AS variant_extension,
                v.byte_size AS variant_byte_size,
                v.width AS variant_width,
                v.height AS variant_height
             FROM ' . $images . ' i
             LEFT JOIN ' . $variants . ' v ON v.image_id = i.id
             WHERE i.page_id = :page_id
             ORDER BY i.sort_order ASC, i.id ASC, v.variant_key ASC'
        );
        $stmt->execute([':page_id' => $pageId]);
        $rows = $stmt->fetchAll() ?: [];

        if ($rows === []) {
            return [];
        }

        $imagesById = [];
        $orderedImageIds = [];
        foreach ($rows as $row) {
            $imageId = (int) $row['id'];
            if ($imageId < 1) {
                continue;
            }

            if (!isset($imagesById[$imageId])) {
                $storedPath = (string) ($row['image_stored_path'] ?? '');
                $imagesById[$imageId] = [
                    'id' => $imageId,
                    'page_id' => (int) ($row['page_id'] ?? 0),
                    'storage_target' => (string) ($row['storage_target'] ?? ''),
                    'original_filename' => (string) ($row['original_filename'] ?? ''),
                    'stored_filename' => (string) ($row['stored_filename'] ?? ''),
                    'stored_path' => $storedPath,
                    'url' => $this->publicUrlFromStoredPath($storedPath),
                    'mime_type' => (string) ($row['mime_type'] ?? ''),
                    'extension' => (string) ($row['extension'] ?? ''),
                    'byte_size' => (int) ($row['byte_size'] ?? 0),
                    'width' => (int) ($row['width'] ?? 0),
                    'height' => (int) ($row['height'] ?? 0),
                    'hash_sha256' => (string) ($row['hash_sha256'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_cover' => (int) ($row['is_cover'] ?? 0) === 1,
                    'is_preview' => (int) ($row['is_preview'] ?? 0) === 1,
                    'include_in_gallery' => (int) ($row['include_in_gallery'] ?? 1) === 1,
                    'alt_text' => (string) ($row['alt_text'] ?? ''),
                    'title_text' => (string) ($row['title_text'] ?? ''),
                    'caption' => (string) ($row['caption'] ?? ''),
                    'credit' => (string) ($row['credit'] ?? ''),
                    'license' => (string) ($row['license'] ?? ''),
                    'focal_x' => $row['focal_x'] === null ? null : (float) $row['focal_x'],
                    'focal_y' => $row['focal_y'] === null ? null : (float) $row['focal_y'],
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
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
                'url' => $this->publicUrlFromStoredPath($variantStoredPath),
                'mime_type' => (string) ($row['variant_mime_type'] ?? ''),
                'extension' => (string) ($row['variant_extension'] ?? ''),
                'byte_size' => (int) ($row['variant_byte_size'] ?? 0),
                'width' => (int) ($row['variant_width'] ?? 0),
                'height' => (int) ($row['variant_height'] ?? 0),
            ];
        }

        $result = [];
        foreach ($orderedImageIds as $imageId) {
            if (isset($imagesById[$imageId])) {
                $result[] = $imagesById[$imageId];
            }
        }

        return $result;
    }

    /**
     * Returns public-ready gallery images for one page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listReadyForPublicPage(int $pageId): array
    {
        $images = $this->listForPage($pageId);
        $ready = [];

        foreach ($images as $image) {
            if ((string) ($image['status'] ?? '') !== 'ready') {
                continue;
            }
            if (array_key_exists('include_in_gallery', $image) && !$image['include_in_gallery']) {
                continue;
            }

            $ready[] = $image;
        }

        // Keep cover image first while preserving explicit manual order for others.
        usort($ready, static function (array $a, array $b): int {
            $aCover = !empty($a['is_cover']) ? 1 : 0;
            $bCover = !empty($b['is_cover']) ? 1 : 0;

            if ($aCover !== $bCover) {
                return $aCover > $bCover ? -1 : 1;
            }

            $aSort = (int) ($a['sort_order'] ?? 0);
            $bSort = (int) ($b['sort_order'] ?? 0);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $ready;
    }

    /**
     * Returns one best-fit public image URL for page-level meta tags.
     *
     * Priority:
     * 1) first ready image marked preview
     * 2) first ready image marked cover
     * 3) first ready image by sort order/id
     */
    public function previewImageUrlForPage(int $pageId): ?string
    {
        $images = $this->listForPage($pageId);
        if ($images === []) {
            return null;
        }

        $ready = [];
        foreach ($images as $image) {
            if ((string) ($image['status'] ?? '') !== 'ready') {
                continue;
            }

            $ready[] = $image;
        }

        if ($ready === []) {
            return null;
        }

        $selectedImage = null;
        foreach ($ready as $image) {
            if (!empty($image['is_preview'])) {
                $selectedImage = $image;
                break;
            }
        }

        if ($selectedImage === null) {
            foreach ($ready as $image) {
                if (!empty($image['is_cover'])) {
                    $selectedImage = $image;
                    break;
                }
            }
        }

        if ($selectedImage === null) {
            $selectedImage = $ready[0];
        }

        $variants = is_array($selectedImage['variants'] ?? null) ? $selectedImage['variants'] : [];
        foreach (['lg', 'med', 'sm'] as $variantKey) {
            $variant = $variants[$variantKey] ?? null;
            $url = trim((string) (is_array($variant) ? ($variant['url'] ?? '') : ''));
            if ($url !== '') {
                return $url;
            }
        }

        $sourceUrl = trim((string) ($selectedImage['url'] ?? ''));
        return $sourceUrl === '' ? null : $sourceUrl;
    }

    /**
     * Updates one page's gallery toggle and per-image metadata.
     *
     * @param array<int, array<string, scalar|null>> $imageUpdates
     */
    public function updateGalleryForPage(int $pageId, bool $enabled, array $imageUpdates): void
    {
        $pages = $this->table('pages');
        $images = $this->table('page_images');
        $now = gmdate('Y-m-d H:i:s');
        $imageUpdates = $this->canonicalizePrimarySelections($imageUpdates);

        $this->db->beginTransaction();

        try {
            // Persist page-level auto-render toggle.
            $updatePage = $this->db->prepare(
                'UPDATE ' . $pages . ' SET gallery_enabled = :gallery_enabled WHERE id = :id'
            );
            $updatePage->execute([
                ':gallery_enabled' => $enabled ? 1 : 0,
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
                         is_cover = :is_cover,
                         is_preview = :is_preview,
                         include_in_gallery = :include_in_gallery,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND page_id = :page_id'
                );

                foreach ($imageUpdates as $imageId => $update) {
                    $updateImage->execute([
                        ':alt_text' => (string) ($update['alt_text'] ?? ''),
                        ':title_text' => (string) ($update['title_text'] ?? ''),
                        ':caption' => (string) ($update['caption'] ?? ''),
                        ':credit' => (string) ($update['credit'] ?? ''),
                        ':license' => (string) ($update['license'] ?? ''),
                        ':focal_x' => $update['focal_x'],
                        ':focal_y' => $update['focal_y'],
                        ':sort_order' => (int) ($update['sort_order'] ?? 1),
                        ':is_cover' => !empty($update['is_cover']) ? 1 : 0,
                        ':is_preview' => !empty($update['is_preview']) ? 1 : 0,
                        ':include_in_gallery' => array_key_exists('include_in_gallery', $update) && empty($update['include_in_gallery']) ? 0 : 1,
                        ':updated_at' => $now,
                        ':id' => (int) $imageId,
                        ':page_id' => $pageId,
                    ]);
                }
            }

            // Final integrity pass ensures at most one cover + one preview across all rows for the page.
            $this->enforceSinglePrimarySelectionsForPage($pageId, $now);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Enforces one cover and one preview selection across a page update payload.
     *
     * @param array<int, array<string, scalar|null>> $imageUpdates
     * @return array<int, array<string, scalar|null>>
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
        $previewWinner = null;
        foreach ($orderedImageIds as $imageId) {
            if (!empty($imageUpdates[$imageId]['is_cover'])) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $imageUpdates[$imageId]['is_cover'] = false;
                }
            }

            if (!empty($imageUpdates[$imageId]['is_preview'])) {
                if ($previewWinner === null) {
                    $previewWinner = $imageId;
                } else {
                    $imageUpdates[$imageId]['is_preview'] = false;
                }
            }
        }

        return $imageUpdates;
    }

    /**
     * Enforces one cover and one preview row per page after metadata updates.
     */
    private function enforceSinglePrimarySelectionsForPage(int $pageId, string $updatedAt): void
    {
        $images = $this->table('page_images');
        $read = $this->db->prepare(
            'SELECT id, sort_order, is_cover, is_preview
             FROM ' . $images . '
             WHERE page_id = :page_id
             ORDER BY sort_order ASC, id ASC'
        );
        $read->execute([':page_id' => $pageId]);
        $rows = $read->fetchAll() ?: [];

        if ($rows === []) {
            return;
        }

        $coverWinner = null;
        $previewWinner = null;
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

            $isPreview = (int) ($row['is_preview'] ?? 0) === 1;
            if ($isPreview) {
                if ($previewWinner === null) {
                    $previewWinner = $imageId;
                } else {
                    $updatesById[$imageId]['is_preview'] = 0;
                }
            }
        }

        if ($updatesById === []) {
            return;
        }

        $updateCover = $this->db->prepare(
            'UPDATE ' . $images . '
             SET is_cover = :value,
                 updated_at = :updated_at
             WHERE id = :id
               AND page_id = :page_id'
        );
        $updatePreview = $this->db->prepare(
            'UPDATE ' . $images . '
             SET is_preview = :value,
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

            if (array_key_exists('is_preview', $flags)) {
                $updatePreview->execute([
                    ':value' => (int) $flags['is_preview'],
                    ':updated_at' => $updatedAt,
                    ':id' => (int) $imageId,
                    ':page_id' => $pageId,
                ]);
            }
        }
    }

    /**
     * Deletes one gallery image + variants and returns stored file paths.
     *
     * @return array{stored_paths: array<int, string>}|null
     */
    public function deleteImageForPage(int $pageId, int $imageId): ?array
    {
        $images = $this->table('page_images');
        $variants = $this->table('page_image_variants');

        $this->db->beginTransaction();

        try {
            $readImage = $this->db->prepare(
                'SELECT stored_path
                 FROM ' . $images . '
                 WHERE id = :id AND page_id = :page_id
                 LIMIT 1'
            );
            $readImage->execute([
                ':id' => $imageId,
                ':page_id' => $pageId,
            ]);
            $imagePath = $readImage->fetchColumn();

            if ($imagePath === false) {
                $this->db->rollBack();
                return null;
            }

            $readVariants = $this->db->prepare(
                'SELECT stored_path FROM ' . $variants . ' WHERE image_id = :image_id'
            );
            $readVariants->execute([':image_id' => $imageId]);
            $variantRows = $readVariants->fetchAll() ?: [];

            $deleteVariants = $this->db->prepare(
                'DELETE FROM ' . $variants . ' WHERE image_id = :image_id'
            );
            $deleteVariants->execute([':image_id' => $imageId]);

            $deleteImage = $this->db->prepare(
                'DELETE FROM ' . $images . ' WHERE id = :id AND page_id = :page_id'
            );
            $deleteImage->execute([
                ':id' => $imageId,
                ':page_id' => $pageId,
            ]);

            $this->db->commit();

            $storedPaths = [(string) $imagePath];
            foreach ($variantRows as $variantRow) {
                $storedPaths[] = (string) ($variantRow['stored_path'] ?? '');
            }

            return ['stored_paths' => array_values(array_filter($storedPaths, static fn (string $path): bool => $path !== ''))];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes all gallery rows for one page and returns all file paths.
     *
     * @return array<int, string>
     */
    public function deleteAllForPage(int $pageId): array
    {
        $images = $this->table('page_images');
        $variants = $this->table('page_image_variants');

        $this->db->beginTransaction();

        try {
            $readPaths = $this->db->prepare(
                'SELECT i.stored_path AS image_path, v.stored_path AS variant_path
                 FROM ' . $images . ' i
                 LEFT JOIN ' . $variants . ' v ON v.image_id = i.id
                 WHERE i.page_id = :page_id'
            );
            $readPaths->execute([':page_id' => $pageId]);
            $rows = $readPaths->fetchAll() ?: [];

            $imageIdsStmt = $this->db->prepare(
                'SELECT id FROM ' . $images . ' WHERE page_id = :page_id'
            );
            $imageIdsStmt->execute([':page_id' => $pageId]);
            $imageIds = array_map(static fn (mixed $value): int => (int) $value, $imageIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if ($imageIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($imageIds), '?'));
                $deleteVariants = $this->db->prepare(
                    'DELETE FROM ' . $variants . ' WHERE image_id IN (' . $placeholders . ')'
                );
                $deleteVariants->execute($imageIds);
            }

            $deleteImages = $this->db->prepare(
                'DELETE FROM ' . $images . ' WHERE page_id = :page_id'
            );
            $deleteImages->execute([':page_id' => $pageId]);

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
     * Converts one stored relative path into a public URL path.
     */
    private function publicUrlFromStoredPath(string $storedPath): string
    {
        return '/' . ltrim($storedPath, '/');
    }

    /**
     * Returns variant rows for a list of image ids.
     *
     * @param array<int> $imageIds
     * @return array<int, array<string, mixed>>
     */
    private function listVariantsByImageIds(array $imageIds, string $variantsTable): array
    {
        if ($imageIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($imageIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT image_id, variant_key, stored_filename, stored_path,
                    mime_type, extension, byte_size, width, height
             FROM ' . $variantsTable . '
             WHERE image_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($imageIds));

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        if ($this->driver !== 'sqlite') {
            // Shared-db mode relies on configurable table prefixes only.
            return $this->prefix . $table;
        }

        // SQLite mode maps logical names onto attached database file aliases.
        return match ($table) {
            'pages' => 'main.pages',
            'page_images' => 'main.page_images',
            'page_image_variants' => 'main.page_image_variants',
            default => 'main.' . $table,
        };
    }
}
