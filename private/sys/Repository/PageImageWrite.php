<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageImageWrite.php
 * Write-side data access for page gallery images and their size variants.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Scribe\PageImageScribe;

/**
 * INSERT, UPDATE, and DELETE methods for per-page gallery images and size variants.
 *
 * Read operations (SELECT, lookup) live in PageImageRead.
 * All SQL mutations are delegated to PageImageScribe.
 */
final class PageImageWrite
{
    private PageImageScribe $pageImageScribe;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->pageImageScribe = new PageImageScribe($db, $driver, $prefix);
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
        return $this->pageImageScribe->insertImageWithVariants($image, $variants);
    }

    /**
     * Updates one page's gallery toggle and per-image metadata.
     *
     * @param int   $pageId       Page whose gallery settings to update.
     * @param bool  $enabled      Whether the gallery block should be rendered publicly.
     * @param array<int, array<string, scalar|null>> $imageUpdates Per-image metadata fields keyed by sequential index.
     */
    public function updateGalleryForPage(int $pageId, bool $enabled, array $imageUpdates): void
    {
        $this->pageImageScribe->updateGalleryForPage($pageId, $enabled, $imageUpdates);
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
        return $this->pageImageScribe->deleteImageForPage($pageId, $imageId);
    }

    /**
     * Deletes all gallery rows for one page and returns all stored file paths.
     *
     * @param int $pageId Page whose images to delete.
     * @return array<int, string> All stored file paths that should be removed from storage.
     */
    public function deleteAllForPage(int $pageId): array
    {
        return $this->pageImageScribe->deleteAllForPage($pageId);
    }
}
