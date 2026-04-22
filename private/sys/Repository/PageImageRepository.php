<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageImageRepository.php
 * Repository for per-page gallery images and generated variants.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Scribe\PageImageScribe;

/**
 * Data access for page gallery images and their size variants.
 */
final class PageImageRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private PageImageScribe $pageImageScribe;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->pageImageScribe = new PageImageScribe($db, $driver, $prefix);
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
        return $this->pageExists($pageId);
    }

    /**
     * Returns the next sort order value for one page's image list.
     */
    public function nextSortOrderForPage(int $pageId): int
    {
        $images = $this->table('page_images');

        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . $images . ' WHERE page = :page'
        );
        $stmt->execute([':page' => $pageId]);

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
             WHERE page = :page
               AND hash = :hash
             LIMIT 1'
        );
        $stmt->execute([
            ':page' => $pageId,
            ':hash' => $sha256,
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
        return $this->pageImageScribe->insertImageWithVariants($image, $variants);
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
        $pages = $this->table('pages');

        $stmt = $this->db->prepare(
            'SELECT
                i.id,
                i.page,
                i.storage_target,
                i.original_filename,
                i.stored_filename,
                i.stored_path AS image_stored_path,
                i.mime_type,
                i.extension,
                i.byte_size,
                i.width,
                i.height,
                i.hash,
                i.status,
                i.sort_order,
                CASE WHEN p.cover_image IS NOT NULL AND p.cover_image = i.id THEN 1 ELSE 0 END AS is_cover,
                i.include_in_gallery,
                i.alt_text,
                i.title_text,
                i.caption,
                i.credit,
                i.license,
                i.focal_x,
                i.focal_y,
                i.created,
                i.updated,
                v.variant_key,
                v.stored_filename AS variant_stored_filename,
                v.stored_path AS variant_stored_path,
                v.mime_type AS variant_mime_type,
                v.extension AS variant_extension,
                v.byte_size AS variant_byte_size,
                v.width AS variant_width,
                v.height AS variant_height
             FROM ' . $images . ' i
             INNER JOIN ' . $pages . ' p ON p.id = i.page
             LEFT JOIN ' . $variants . ' v ON v.image = i.id
             WHERE i.page = :page
             ORDER BY i.sort_order ASC, i.id ASC, v.variant_key ASC'
        );
        $stmt->execute([':page' => $pageId]);
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
                    'page' => (int) ($row['page'] ?? 0),
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
                    'hash' => (string) ($row['hash'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_cover' => (int) ($row['is_cover'] ?? 0) === 1,
                    'include_in_gallery' => (int) ($row['include_in_gallery'] ?? 1) === 1,
                    'alt_text' => (string) ($row['alt_text'] ?? ''),
                    'title_text' => (string) ($row['title_text'] ?? ''),
                    'caption' => (string) ($row['caption'] ?? ''),
                    'credit' => (string) ($row['credit'] ?? ''),
                    'license' => (string) ($row['license'] ?? ''),
                    'focal_x' => $row['focal_x'] === null ? null : (float) $row['focal_x'],
                    'focal_y' => $row['focal_y'] === null ? null : (float) $row['focal_y'],
                    'created' => (string) ($row['created'] ?? ''),
                    'updated' => (string) ($row['updated'] ?? ''),
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
     * Only an explicit ready cover image can override site-level meta image config.
     * When present, the public wrapper uses that image's large variant for both
     * OpenGraph and X/Twitter tags via the shared `meta:image` template value.
     */
    public function coverImageUrlForPage(int $pageId): ?string
    {
        $images = $this->listForPage($pageId);
        if ($images === []) {
            return null;
        }

        foreach ($images as $image) {
            if ((string) ($image['status'] ?? '') !== 'ready') {
                continue;
            }
            if (!empty($image['is_cover'])) {
                $variants = is_array($image['variants'] ?? null) ? $image['variants'] : [];
                $largeVariant = $variants['lg'] ?? null;
                $url = trim((string) (is_array($largeVariant) ? ($largeVariant['url'] ?? '') : ''));
                return $url !== '' ? $url : null;
            }
        }

        return null;
    }

    /**
     * Updates one page's gallery toggle and per-image metadata.
     *
     * @param array<int, array<string, scalar|null>> $imageUpdates
     */
    public function updateGalleryForPage(int $pageId, bool $enabled, array $imageUpdates): void
    {
        $this->pageImageScribe->updateGalleryForPage($pageId, $enabled, $imageUpdates);
    }

    /**
     * Deletes one gallery image + variants and returns stored file paths.
     *
     * @return array{stored_paths: array<int, string>}|null
     */
    public function deleteImageForPage(int $pageId, int $imageId): ?array
    {
        return $this->pageImageScribe->deleteImageForPage($pageId, $imageId);
    }

    /**
     * Deletes all gallery rows for one page and returns all file paths.
     *
     * @return array<int, string>
     */
    public function deleteAllForPage(int $pageId): array
    {
        return $this->pageImageScribe->deleteAllForPage($pageId);
    }

    /**
     * Converts one stored relative path into a public URL path.
     */
    private function publicUrlFromStoredPath(string $storedPath): string
    {
        return '/' . ltrim($storedPath, '/');
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
