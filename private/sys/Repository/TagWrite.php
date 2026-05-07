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
use Raven\Lib\Database\SqlTable;

/**
 * INSERT, UPDATE, and DELETE methods for page tags.
 */
final class TagWrite
{
    private TagRead $read;
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO     $db     Active database connection.
     * @param string  $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string  $prefix Table name prefix for this Raven installation.
     * @param TagRead $read   Read-side instance used for existence lookups during validation.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, TagRead $read)
    {
        $this->read = $read;
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Creates or updates one tag and returns the tag id.
     *
     * @param array{id: int|null, name: string, slug: string, set: int, description: string} $data Normalized tag fields.
     * @return int The saved tag id.
     */
    public function save(array $data): int
    {
        $tags = $this->table('tags');
        $id = $data['id'] ?? null;
        $name = (string) ($data['name'] ?? '');
        $slug = (string) ($data['slug'] ?? '');
        $setId = max(1, (int) ($data['set'] ?? 1));
        $description = (string) ($data['description'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        if ($id !== null && $id > 0) {
            $stmt = $this->db->prepare(
                'UPDATE ' . $tags . '
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

        $stmt = $this->db->prepare(
            'INSERT INTO ' . $tags . ' (name, slug, ' . $this->setColumn() . ', description, created, updated)
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
     * Updates one tag's cover/preview/icon image files.
     *
     * @param int   $id    Tag id to update.
     * @param array{
     *   cover_image?: string|null,
     *   preview_image?: string|null,
     *   icon_image?: string|null
     * } $files Image path strings keyed by image role.
     * @return void
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'UPDATE ' . $tags . '
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
     * Moves all tags in the given set to the default set.
     *
     * @param int $fromSetId    Taxonomy set id being removed.
     * @param int $defaultSetId Taxonomy set id to reassign orphaned tags to.
     * @return void
     */
    public function reassignSetToDefault(int $fromSetId, int $defaultSetId): void
    {
        if ($fromSetId === $defaultSetId) {
            return;
        }

        $tags = $this->table('tags');
        $stmt = $this->db->prepare(
            'UPDATE ' . $tags . '
             SET ' . $this->setColumn() . ' = :default_set
             WHERE ' . $this->setColumn() . ' = :from_set'
        );
        $stmt->execute([':default_set' => $defaultSetId, ':from_set' => $fromSetId]);
    }

    /**
     * Deletes one tag and removes its page assignment rows.
     *
     * @param int $id Tag id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $tags = $this->table('tags');
        $relationTable = $this->table('page_tags');

        $this->db->beginTransaction();

        try {
            $detach = $this->db->prepare(
                'DELETE FROM ' . $relationTable . ' WHERE tag = :taxonomy_id'
            );
            $detach->execute([':taxonomy_id' => $id]);

            $delete = $this->db->prepare('DELETE FROM ' . $tags . ' WHERE id = :id');
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
