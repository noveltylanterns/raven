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
use Raven\Lib\Scribe\MediaScribe;

/**
 * INSERT, UPDATE, and DELETE methods for page-scoped media rows and size variants.
 *
 * Read operations (SELECT, lookup) live in MediaRead.
 * All SQL mutations are delegated to MediaScribe.
 */
final class MediaWrite
{
    private MediaScribe $mediaScribe;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->mediaScribe = new MediaScribe($db, $driver, $prefix);
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
        return $this->mediaScribe->insertImageWithVariants($image, $variants);
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
        $this->mediaScribe->updateGalleryForPage($pageId, $imageUpdates);
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
        return $this->mediaScribe->deleteImageForPage($pageId, $imageId);
    }

    /**
     * Deletes all gallery rows for one page and returns all stored file paths.
     *
     * @param int $pageId Page whose images to delete.
     * @return array<int, string> All stored file paths that should be removed from storage.
     */
    public function deleteAllForPage(int $pageId): array
    {
        return $this->mediaScribe->deleteAllForPage($pageId);
    }
}
