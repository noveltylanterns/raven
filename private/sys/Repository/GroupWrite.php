<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/GroupWrite.php
 * Write-side data access for user-group records (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Scribe\GroupScribe;

/**
 * INSERT, UPDATE, and DELETE methods for user groups.
 *
 * Read operations (SELECT, lookup) live in GroupRead, which is injected here
 * so that write-side validation can perform existence checks without duplicating queries.
 */
final class GroupWrite
{
    private GroupRead $read;
    private GroupScribe $groupScribe;

    /**
     * @param PDO       $db     Active database connection.
     * @param string    $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string    $prefix Table name prefix for this Raven installation.
     * @param GroupRead $read   Read-side instance used for existence lookups during validation.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, GroupRead $read)
    {
        $this->read = $read;
        $this->groupScribe = new GroupScribe($db, $driver, $prefix);
    }

    /**
     * Creates or updates one group and returns the group id.
     *
     * Stock-group slugs are immutable; stock names are editable.
     * The stock flag cannot be changed through the normal save flow.
     *
     * @param array{id: int|null, name: string, slug?: string, description?: string, route?: int|bool, permissions?: int} $data Normalized group fields.
     * @return int The saved group id.
     */
    public function save(array $data): int
    {
        return $this->groupScribe->save($data);
    }

    /**
     * Updates one group's cover and icon image files.
     *
     * Groups use filename storage (same pattern as categories/tags) — the preview slot is not supported.
     *
     * @param int   $id    Group id to update.
     * @param array{
     *   cover_image?: string|null,
     *   icon_image?: string|null
     * } $files Image path strings keyed by image role.
     * @return void
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $this->groupScribe->updateImageFiles($id, $files);
    }

    /**
     * Deletes one non-stock group and reassigns affected users to `User`
     * when they would otherwise have zero memberships.
     *
     * @param int $id Group id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $this->groupScribe->deleteById($id);
    }
}
