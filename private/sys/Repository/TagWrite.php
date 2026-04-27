<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/TagWrite.php
 * Write-side data access for page tag records (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Scribe\TaxonomyScribe;

/**
 * INSERT, UPDATE, and DELETE methods for page tags.
 *
 * Read operations (SELECT, lookup) live in TagRead, which is injected here
 * so that write-side validation can perform existence checks without duplicating queries.
 */
final class TagWrite
{
    private TagRead $read;
    private TaxonomyScribe $tagScribe;

    /**
     * @param PDO     $db     Active database connection.
     * @param string  $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string  $prefix Table name prefix for this Raven installation.
     * @param TagRead $read   Read-side instance used for existence lookups during validation.
     */
    public function __construct(PDO $db, string $driver, string $prefix, TagRead $read)
    {
        $this->read = $read;
        $this->tagScribe = new TaxonomyScribe($db, $driver, $prefix, 'tag');
    }

    /**
     * Creates or updates one tag and returns the tag id.
     *
     * @param array{id: int|null, name: string, slug: string, set: int, description: string} $data Normalized tag fields.
     * @return int The saved tag id.
     */
    public function save(array $data): int
    {
        return $this->tagScribe->save($data);
    }

    /**
     * Updates one tag's cover/preview/icon image files.
     *
     * @param int   $id    Tag id to update.
     * @param array{
     *   cover_image?: string|null,
     *   preview_image?: string|null,
     *   icon_image?: string|null
     * } $files Image path strings keyed by image role.
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $this->tagScribe->updateImageFiles($id, $files);
    }

    /**
     * Moves all tags in the given set to the default set.
     *
     * Called before deleting a taxonomy set so no tags are left with a
     * dangling set reference.
     *
     * @param int $fromSetId    Taxonomy set id being removed.
     * @param int $defaultSetId Taxonomy set id to reassign orphaned tags to.
     */
    public function reassignSetToDefault(int $fromSetId, int $defaultSetId): void
    {
        $this->tagScribe->reassignSetToDefault($fromSetId, $defaultSetId);
    }

    /**
     * Deletes one tag and removes its page assignment rows.
     *
     * @param int $id Tag id to delete.
     */
    public function deleteById(int $id): void
    {
        $this->tagScribe->deleteById($id);
    }
}
