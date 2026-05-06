<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/CategoryWrite.php
 * Write-side data access for page category records (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Scribe\CategoryScribe;

/**
 * INSERT, UPDATE, and DELETE methods for page categories.
 *
 * Read operations (SELECT, lookup) live in CategoryRead, which is injected here
 * so that write-side validation can perform existence checks without duplicating queries.
 */
final class CategoryWrite
{
    private CategoryRead $read;
    private CategoryScribe $categoryScribe;

    /**
     * @param PDO          $db     Active database connection.
     * @param string       $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string       $prefix Table name prefix for this Raven installation.
     * @param CategoryRead $read   Read-side instance used for existence lookups during validation.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, CategoryRead $read)
    {
        $this->read = $read;
        $this->categoryScribe = new CategoryScribe($db, $driver, $prefix);
    }

    /**
     * Creates or updates one category and returns the category id.
     *
     * @param array{id: int|null, name: string, slug: string, set: int, description: string} $data Normalized category fields.
     * @return int The saved category id.
     */
    public function save(array $data): int
    {
        return $this->categoryScribe->save($data);
    }

    /**
     * Updates one category's cover/preview/icon image files.
     *
     * @param int   $id    Category id to update.
     * @param array{
     *   cover_image?: string|null,
     *   preview_image?: string|null,
     *   icon_image?: string|null
     * } $files Image path strings keyed by image role.
     * @return void
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $this->categoryScribe->updateImageFiles($id, $files);
    }

    /**
     * Moves all categories in the given set to the default set.
     *
     * Called before deleting a taxonomy set so no categories are left with a
     * dangling set reference.
     *
     * @param int $fromSetId    Taxonomy set id being removed.
     * @param int $defaultSetId Taxonomy set id to reassign orphaned categories to.
     * @return void
     */
    public function reassignSetToDefault(int $fromSetId, int $defaultSetId): void
    {
        $this->categoryScribe->reassignSetToDefault($fromSetId, $defaultSetId);
    }

    /**
     * Deletes one category and removes its page assignment rows.
     *
     * @param int $id Category id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $this->categoryScribe->deleteById($id);
    }
}
