<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/CategoryWrite.php
 * Write-side data access for page category records (INSERT, UPDATE, DELETE).
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;

/**
 * INSERT, UPDATE, and DELETE methods for page categories.
 */
final class CategoryWrite
{
    private CategoryRead $read;
    private PDO $db;
    private string $driver;
    private string $prefix;

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
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Creates or updates one category and returns the category id.
     *
     * @param array{id: int|null, name: string, slug: string, set: int, description: string} $data Normalized category fields.
     * @return int The saved category id.
     */
    public function save(array $data): int
    {
        $categories = $this->table('categories');
        $id = $data['id'] ?? null;
        $name = (string) ($data['name'] ?? '');
        $slug = (string) ($data['slug'] ?? '');
        $setId = max(1, (int) ($data['set'] ?? 1));
        $description = (string) ($data['description'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        if ($id !== null && $id > 0) {
            // Keep updates in-place so controllers can preserve existing ids and attachments.
            $stmt = $this->db->prepare(
                'UPDATE ' . $categories . '
                 SET name = :name,
                     slug = :slug,
                     ' . $this->setColumn() . ' = :set_id,
                     description = :description,
                     updated = :updated
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':set_id' => $setId,
                ':description' => $description,
                ':updated' => $now,
                ':id' => $id,
            ]);

            return $id;
        }

        // New rows stamp both created and updated so panel lists stay consistent with legacy reads.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $categories . ' (name, slug, ' . $this->setColumn() . ', description, created, updated)
             VALUES (:name, :slug, :set_id, :description, :created_at, :updated)'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':set_id' => $setId,
            ':description' => $description,
            ':created_at' => $now,
            ':updated' => $now,
        ]);

        return (int) $this->db->lastInsertId();
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
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'UPDATE ' . $categories . '
             SET cover_image = :cover_image,
                 preview_image = :preview_image,
                 icon_image = :icon_image,
                 updated = :updated
             WHERE id = :id'
        );
        $stmt->execute([
            ':cover_image' => $this->normalizeNullableFilename($files['cover_image'] ?? null),
            ':preview_image' => $this->normalizeNullableFilename($files['preview_image'] ?? null),
            ':icon_image' => $this->normalizeNullableFilename($files['icon_image'] ?? null),
            ':updated' => gmdate('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
    }

    /**
     * Moves all categories in the given set to the default set.
     *
     * @param int $fromSetId    Taxonomy set id being removed.
     * @param int $defaultSetId Taxonomy set id to reassign orphaned categories to.
     * @return void
     */
    public function reassignSetToDefault(int $fromSetId, int $defaultSetId): void
    {
        if ($fromSetId === $defaultSetId) {
            return;
        }

        $categories = $this->table('categories');
        $stmt = $this->db->prepare(
            'UPDATE ' . $categories . '
             SET ' . $this->setColumn() . ' = :default_set
             WHERE ' . $this->setColumn() . ' = :from_set'
        );
        $stmt->execute([':default_set' => $defaultSetId, ':from_set' => $fromSetId]);
    }

    /**
     * Deletes one category and removes its page assignment rows.
     *
     * @param int $id Category id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $categories = $this->table('categories');
        $relationTable = $this->table('page_categories');

        $this->db->beginTransaction();

        try {
            // Detach page links first so no join-table rows survive after the taxonomy row disappears.
            $detach = $this->db->prepare(
                'DELETE FROM ' . $relationTable . ' WHERE category = :taxonomy_id'
            );
            $detach->execute([':taxonomy_id' => $id]);

            $delete = $this->db->prepare('DELETE FROM ' . $categories . ' WHERE id = :id');
            $delete->execute([':id' => $id]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string Physical table name for the active database backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Returns the correctly quoted taxonomy-set column name.
     *
     * @param string|null $alias Optional SQL table alias prefix.
     * @return string Quoted `set` column reference.
     */
    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }

    /**
     * Normalizes one optional image filename down to a storage-safe basename.
     *
     * @param mixed $value Raw submitted or computed filename value.
     * @return string|null Basename-only filename, or null when the field should be cleared.
     */
    private function normalizeNullableFilename(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return basename(str_replace('\\', '/', $raw));
    }
}
