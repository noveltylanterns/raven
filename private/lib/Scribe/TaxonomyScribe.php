<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/TaxonomyScribe.php
 * Shared base class for category/tag taxonomy record persistence.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Lib\Database\SqlTable;

/**
 * Shared write-side SQL base for category/tag taxonomy records.
 *
 * CategoryScribe and TagScribe narrow the concrete table/link-table selection
 * while this base keeps the shared mutation rules in one canonical place.
 */
abstract class TaxonomyScribe
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * Prepares the shared taxonomy write helper.
     *
     * @param PDO    $db     App database connection used for taxonomy writes.
     * @param string $driver Active PDO driver name used when quoting reserved columns.
     * @param string $prefix Application table prefix before resolver sanitization.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Creates or updates one taxonomy row and returns its id.
     *
     * @param array{id: int|null, name: string, slug: string, set: int, description: string} $data Normalized taxonomy payload.
     * @return int Persisted taxonomy id.
     */
    public function save(array $data): int
    {
        $taxonomyTable = $this->taxonomyTable();
        $id = $data['id'] ?? null;
        $name = (string) ($data['name'] ?? '');
        $slug = (string) ($data['slug'] ?? '');
        $setId = max(1, (int) ($data['set'] ?? 1));
        $description = (string) ($data['description'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        if ($id !== null && $id > 0) {
            // Keep updates in-place so controllers can preserve existing ids and attachments.
            $stmt = $this->db->prepare(
                'UPDATE ' . $taxonomyTable . '
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
            'INSERT INTO ' . $taxonomyTable . ' (name, slug, ' . $this->setColumn() . ', description, created, updated)
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
     * Updates the taxonomy image filename columns for one row.
     *
     * @param int                                                                       $id    Taxonomy id to update.
     * @param array{cover_image?: string|null, preview_image?: string|null, icon_image?: string|null} $files Storage filenames to persist.
     * @return void
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $taxonomyTable = $this->taxonomyTable();

        $stmt = $this->db->prepare(
            'UPDATE ' . $taxonomyTable . '
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
     * Moves all rows from one taxonomy set id to the stock default set id.
     *
     * @param int $fromSetId    Set id being retired.
     * @param int $defaultSetId Fallback set id that should inherit those rows.
     * @return void
     */
    public function reassignSetToDefault(int $fromSetId, int $defaultSetId): void
    {
        if ($fromSetId === $defaultSetId) {
            return;
        }

        $taxonomyTable = $this->taxonomyTable();
        $stmt = $this->db->prepare(
            'UPDATE ' . $taxonomyTable . '
             SET ' . $this->setColumn() . ' = :default_set
             WHERE ' . $this->setColumn() . ' = :from_set'
        );
        $stmt->execute([':default_set' => $defaultSetId, ':from_set' => $fromSetId]);
    }

    /**
     * Deletes one taxonomy row and clears its page-link assignments in the same transaction.
     *
     * @param int $id Taxonomy id to delete.
     * @throws \Throwable Re-throws any database failure after rolling back the transaction.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $taxonomyTable = $this->taxonomyTable();
        $relationTable = $this->relationTable();
        $relationColumn = $this->relationColumn();

        $this->db->beginTransaction();

        try {
            // Detach page links first so no join-table rows survive after the taxonomy row disappears.
            $detach = $this->db->prepare(
                'DELETE FROM ' . $relationTable . ' WHERE ' . $relationColumn . ' = :taxonomy_id'
            );
            $detach->execute([':taxonomy_id' => $id]);

            $delete = $this->db->prepare('DELETE FROM ' . $taxonomyTable . ' WHERE id = :id');
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
     * Resolves the physical table name for the active taxonomy type.
     *
     * @return string Quoted-ready physical table name.
     */
    private function taxonomyTable(): string
    {
        return $this->table($this->taxonomyTableKey());
    }

    /**
     * Resolves the page-link table for the active taxonomy type.
     *
     * @return string Quoted-ready physical table name.
     */
    private function relationTable(): string
    {
        return $this->table($this->relationTableKey());
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string       Physical table name for the active database backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
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

    /**
     * Returns the correctly quoted taxonomy-set column name.
     *
     * @param string|null $alias Optional SQL table alias prefix.
     * @return string            Quoted `set` column reference.
     */
    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }

    /**
     * Returns the logical taxonomy table name for the concrete scribe.
     *
     * @return string Unprefixed taxonomy table name.
     */
    abstract protected function taxonomyTableKey(): string;

    /**
     * Returns the logical page-link table name for the concrete scribe.
     *
     * @return string Unprefixed page-link table name.
     */
    abstract protected function relationTableKey(): string;

    /**
     * Returns the join-table column that points back to the taxonomy id.
     *
     * @return string Plain SQL column name used inside the link table.
     */
    abstract protected function relationColumn(): string;
}
