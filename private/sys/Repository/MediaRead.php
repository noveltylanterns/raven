<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/MediaRead.php
 * Read-only data access for page-scoped media rows and their size variants.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\TableNameResolver;

/**
 * SELECT and lookup methods for page-scoped media rows and size variants.
 *
 * Write operations (insert, update, delete) live in MediaWrite.
 * Both the panel editor and public cover-image detection use methods here.
 */
class MediaRead
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns true when a page with the given id exists.
     *
     * @param int $pageId Page id to check.
     * @return bool True when the page row is found.
     */
    public function pageExists(int $pageId): bool
    {
        $pages = $this->table('pages');

        $stmt = $this->db->prepare('SELECT 1 FROM ' . $pages . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pageId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when the given page exists and is therefore eligible to hold gallery images.
     *
     * Gallery image storage is always available for any existing page; the per-page
     * `gallery_enabled` flag on the pages table controls whether the gallery block is
     * rendered publicly, not whether images may be uploaded.
     *
     * @param int $pageId Page id to check.
     * @return bool True when the page exists.
     */
    public function isGalleryEnabledForPage(int $pageId): bool
    {
        return $this->pageExists($pageId);
    }

    /**
     * Returns the next sort order value for one page's image list.
     *
     * @param int $pageId Page whose current maximum sort order is inspected.
     * @return int Next available sort order value (at least 1).
     */
    public function nextSortOrderForPage(int $pageId): int
    {
        $images = $this->table('media');

        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . $images . ' WHERE page = :page'
        );
        $stmt->execute([':page' => $pageId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Returns true when one page already has an image with the same SHA-256 hash.
     *
     * @param int    $pageId  Page id to scope the duplicate check.
     * @param string $sha256  SHA-256 hash of the candidate image file.
     * @return bool True when an exact duplicate exists on this page.
     */
    public function hasHashForPage(int $pageId, string $sha256): bool
    {
        $images = $this->table('media');

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
     * Returns all images and variants for one page, sorted by sort_order then id.
     *
     * Used by both the panel editor and public cover-image detection.
     *
     * @param int $pageId Page id whose images to load.
     * @return array<int, array<string, mixed>> Hydrated image rows with inline variant arrays.
     */
    public function listForPage(int $pageId): array
    {
        $images = $this->table('media');
        $variants = $this->table('media_variants');
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
     * Filters to status=ready and include_in_gallery=true, then places the cover image first
     * while preserving explicit manual order for the rest.
     *
     * @param int $pageId Page whose public gallery images to load.
     * @return array<int, array<string, mixed>> Filtered and sorted image rows.
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
     *
     * @param int $pageId Page whose cover image URL to resolve.
     * @return string|null Public URL of the large cover image variant, or null when absent.
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
     * Converts one stored relative path into a public URL path.
     *
     * @param string $storedPath Relative storage path from the database.
     * @return string Absolute-rooted public URL path.
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
