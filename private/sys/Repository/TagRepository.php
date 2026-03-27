<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/TagRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use Raven\Lib\Database\Runtime\TableNameResolver;
use Raven\Lib\Media\TaxonomyImagePathResolver;

/**
 * Data access for Tag CRUD operations in panel.
 */
final class TagRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns all tags with linked page counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.slug, t.set_id, t.description, t.created_at,
                    t.cover_image_file, t.preview_image_file,
                    COALESCE(pt.page_count, 0) AS page_count
             FROM ' . $tags . ' t
             LEFT JOIN (
                 SELECT tag_id, COUNT(*) AS page_count
                 FROM ' . $pageTags . '
                 GROUP BY tag_id
             ) pt ON pt.tag_id = t.id
             ORDER BY t.name ASC, t.id ASC'
        );
        // LEFT JOIN keeps tags with zero linked pages visible in admin listings.
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: []);
    }

    /**
     * Returns one total-count for panel tag index.
     */
    public function countForPanel(?int $setId = null): int
    {
        $tags = $this->table('tags');
        $sql = 'SELECT COUNT(*) FROM ' . $tags;
        $params = [];
        if ($setId !== null && $setId >= 0) {
            $sql .= ' WHERE set_id = :set_id';
            $params[':set_id'] = $setId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated tags with linked page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE t.set_id = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.slug, t.set_id, t.description, t.created_at,
                    t.cover_image_file, t.preview_image_file,
                    COALESCE(pt.page_count, 0) AS page_count
             FROM ' . $tags . ' t
             LEFT JOIN (
                 SELECT tag_id, COUNT(*) AS page_count
                 FROM ' . $pageTags . '
                 GROUP BY tag_id
             ) pt ON pt.tag_id = t.id
             ' . $whereSql . '
             ORDER BY t.name ASC, t.id ASC
             LIMIT :limit OFFSET :offset'
        );
        if ($setId !== null && $setId >= 0) {
            $stmt->bindValue(':set_id', $setId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: []);
    }

    /**
     * Returns one paginated tag page plus total row count in one query.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE t.set_id = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.name,
                    page_rows.slug,
                    page_rows.set_id,
                    page_rows.description,
                    page_rows.created_at,
                    page_rows.cover_image_file,
                    page_rows.preview_image_file,
                    page_rows.page_count,
                    totals.total_rows
             FROM (
                 SELECT t.id, t.name, t.slug, t.set_id, t.description, t.created_at,
                        t.cover_image_file, t.preview_image_file,
                        COALESCE(pt.page_count, 0) AS page_count
                 FROM ' . $tags . ' t
                 LEFT JOIN (
                     SELECT tag_id, COUNT(*) AS page_count
                     FROM ' . $pageTags . '
                     GROUP BY tag_id
                 ) pt ON pt.tag_id = t.id
                 ' . $whereSql . '
                 ORDER BY t.name ASC, t.id ASC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $tags . ($setId !== null && $setId >= 0 ? ' WHERE set_id = :set_id_total' : '') . '
             ) AS totals'
        );
        if ($setId !== null && $setId >= 0) {
            $stmt->bindValue(':set_id', $setId, PDO::PARAM_INT);
            $stmt->bindValue(':set_id_total', $setId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $total = 0;
        $resultRows = [];
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->hydrateRow($row);
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->countForPanel($setId);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns minimal tag options for panel select controls.
     *
     * @return array<int, array{id: int, name: string, slug: string, set_id: int}>
     */
    public function listOptions(): array
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, set_id
             FROM ' . $tags . '
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'set_id' => (int) ($row['set_id'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Returns only ids that currently exist in storage.
     *
     * @param array<int> $ids
     * @return array<int>
     */
    public function existingIds(array $ids): array
    {
        $normalizedIds = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalizedIds[$value] = $value;
            }
        }

        if ($normalizedIds === []) {
            return [];
        }

        $tags = $this->table('tags');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $tags . '
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($normalizedIds));

        $rows = $stmt->fetchAll() ?: [];
        $existing = [];
        foreach ($rows as $row) {
            $value = (int) ($row['id'] ?? 0);
            if ($value > 0) {
                $existing[$value] = $value;
            }
        }

        return array_values($existing);
    }

    /**
     * Returns one tag by id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, set_id, description, created_at,
                    cover_image_file, preview_image_file
             FROM ' . $tags . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Creates or updates one tag and returns tag id.
     *
     * @param array{id: int|null, name: string, slug: string, set_id: int, description: string} $data
     */
    public function save(array $data): int
    {
        $tags = $this->table('tags');

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $slug = $data['slug'];
        $setId = max(1, (int) ($data['set_id'] ?? 1));
        $description = $data['description'];

        if ($id !== null && $id > 0) {
            // Update existing row when an id is present.
            $stmt = $this->db->prepare(
                'UPDATE ' . $tags . '
                 SET name = :name,
                     slug = :slug,
                     set_id = :set_id,
                     description = :description
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':set_id' => $setId,
                ':description' => $description,
                ':id' => $id,
            ]);

            return $id;
        }

        // Insert path creates a new tag with creation timestamp.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $tags . ' (name, slug, set_id, description, created_at)
             VALUES (:name, :slug, :set_id, :description, :created_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':set_id' => $setId,
            ':description' => $description,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates one tag's cover/preview image files.
     *
     * @param array{
     *   cover_image_file?: string|null,
     *   preview_image_file?: string|null
     * } $files
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'UPDATE ' . $tags . '
             SET cover_image_file = :cover_image_file,
                 preview_image_file = :preview_image_file
             WHERE id = :id'
        );
        $stmt->execute([
            ':cover_image_file' => $this->normalizeNullableFilename($files['cover_image_file'] ?? null),
            ':preview_image_file' => $this->normalizeNullableFilename($files['preview_image_file'] ?? null),
            ':id' => $id,
        ]);
    }

    /**
     * Deletes one tag and removes page-tag links.
     */
    public function deleteById(int $id): void
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $this->db->beginTransaction();

        try {
            // Remove tag links before deleting the tag row.
            $detach = $this->db->prepare(
                'DELETE FROM ' . $pageTags . ' WHERE tag_id = :tag_id'
            );
            $detach->execute([':tag_id' => $id]);

            $delete = $this->db->prepare('DELETE FROM ' . $tags . ' WHERE id = :id');
            $delete->execute([':id' => $id]);

            // Commit only after relation cleanup and tag delete both succeed.
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int> $ids
     * @return array<int, int>
     */
    public function setIdsByIds(array $ids): array
    {
        $normalizedIds = $this->existingIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        $tags = $this->table('tags');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id, set_id
             FROM ' . $tags . '
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($normalizedIds));

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) ($row['id'] ?? 0)] = (int) ($row['set_id'] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    public function countsBySetId(): array
    {
        $tags = $this->table('tags');
        $stmt = $this->db->prepare(
            'SELECT set_id, COUNT(*) AS row_count
             FROM ' . $tags . '
             GROUP BY set_id'
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) ($row['set_id'] ?? 0)] = (int) ($row['row_count'] ?? 0);
        }

        return $result;
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrateRow($row);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $storage = TaxonomyImagePathResolver::storagePayloadFromRecord('tags', $row);
        $row['cover_image_file'] = $storage['cover_image_file'] ?? null;
        $row['preview_image_file'] = $storage['preview_image_file'] ?? null;

        return array_merge(
            $row,
            TaxonomyImagePathResolver::pathsFromStoragePayload('tags', $id, $storage)
        );
    }

    private function normalizeNullableFilename(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return basename(str_replace('\\', '/', $raw));
    }
}
