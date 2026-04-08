<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/CategoryRepository.php
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
 * Data access for Category CRUD operations in panel.
 */
final class CategoryRepository
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
     * Returns all categories with linked page counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $setColumn = $this->setColumn('c');

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, ' . $setColumn . ', c.description, c.created, c.updated,
                    c.cover_image, c.preview_image, c.icon_image,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $categories . ' c
             LEFT JOIN (
                 SELECT category, COUNT(*) AS page_count
                 FROM ' . $pageCategories . '
                 GROUP BY category
             ) pc ON pc.category = c.id
             ORDER BY c.name ASC, c.id ASC'
        );
        // LEFT JOIN keeps categories with zero linked pages visible in admin listings.
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: []);
    }

    /**
     * Returns one total-count for panel category index.
     */
    public function countForPanel(?int $setId = null): int
    {
        $categories = $this->table('categories');
        $sql = 'SELECT COUNT(*) FROM ' . $categories;
        $params = [];
        if ($setId !== null && $setId >= 0) {
            $sql .= ' WHERE ' . $this->setColumn() . ' = :set_id';
            $params[':set_id'] = $setId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated categories with linked page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE ' . $this->setColumn('c') . ' = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, ' . $this->setColumn('c') . ', c.description, c.created, c.updated,
                    c.cover_image, c.preview_image, c.icon_image,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $categories . ' c
             LEFT JOIN (
                 SELECT category, COUNT(*) AS page_count
                 FROM ' . $pageCategories . '
                 GROUP BY category
             ) pc ON pc.category = c.id
             ' . $whereSql . '
             ORDER BY c.name ASC, c.id ASC
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
     * Returns one paginated category page plus total row count in one query.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE ' . $this->setColumn('c') . ' = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.name,
                    page_rows.slug,
                    ' . $this->setColumn('page_rows') . ',
                    page_rows.description,
                    page_rows.created,
                    page_rows.updated,
                    page_rows.cover_image,
                    page_rows.preview_image,
                    page_rows.icon_image,
                    page_rows.page_count,
                    totals.total_rows
             FROM (
                 SELECT c.id, c.name, c.slug, ' . $this->setColumn('c') . ', c.description, c.created, c.updated,
                        c.cover_image, c.preview_image, c.icon_image,
                        COALESCE(pc.page_count, 0) AS page_count
                 FROM ' . $categories . ' c
                 LEFT JOIN (
                     SELECT category, COUNT(*) AS page_count
                     FROM ' . $pageCategories . '
                     GROUP BY category
                 ) pc ON pc.category = c.id
                 ' . $whereSql . '
                 ORDER BY c.name ASC, c.id ASC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $categories . ($setId !== null && $setId >= 0 ? ' WHERE ' . $this->setColumn() . ' = :set_id_total' : '') . '
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
     * Returns minimal category options for panel select controls.
     *
     * @return array<int, array{id: int, name: string, slug: string, set: int}>
     */
    public function listOptions(): array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . '
             FROM ' . $categories . '
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
                'set' => (int) ($row['set'] ?? 0),
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

        $categories = $this->table('categories');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $categories . '
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
     * Returns one category by id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . ', description, created, updated,
                    cover_image, preview_image, icon_image
             FROM ' . $categories . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Returns one category by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . ', description, created, updated,
                    cover_image, preview_image, icon_image
             FROM ' . $categories . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Returns one category id by slug, or null when not found.
     */
    public function idBySlug(string $slug): ?int
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id FROM ' . $categories . ' WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Creates or updates one category and returns category id.
     *
     * @param array{id: int|null, name: string, slug: string, set: int, description: string} $data
     */
    public function save(array $data): int
    {
        $categories = $this->table('categories');

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $slug = $data['slug'];
        $setId = max(1, (int) ($data['set'] ?? 1));
        $description = $data['description'];
        $now = gmdate('Y-m-d H:i:s');

        if ($id !== null && $id > 0) {
            // Update existing row when an id is present.
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

        // Insert path creates a new category with creation timestamp.
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
     * @param array{
     *   cover_image?: string|null,
     *   preview_image?: string|null,
     *   icon_image?: string|null
     * } $files
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
     * Deletes one category and removes category assignments from pages.
     */
    public function deleteById(int $id): void
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');

        $this->db->beginTransaction();

        try {
            // Remove category links before deleting the category row.
            $detach = $this->db->prepare(
                'DELETE FROM ' . $pageCategories . ' WHERE category = :category'
            );
            $detach->execute([':category' => $id]);

            $delete = $this->db->prepare('DELETE FROM ' . $categories . ' WHERE id = :id');
            $delete->execute([':id' => $id]);

            // Commit only after relation cleanup and category delete both succeed.
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

        $categories = $this->table('categories');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id, ' . $this->setColumn() . '
             FROM ' . $categories . '
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($normalizedIds));

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) ($row['id'] ?? 0)] = (int) ($row['set'] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    public function countsBySetId(): array
    {
        $categories = $this->table('categories');
        $stmt = $this->db->prepare(
            'SELECT ' . $this->setColumn() . ', COUNT(*) AS row_count
             FROM ' . $categories . '
             GROUP BY ' . $this->setColumn()
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) ($row['set'] ?? 0)] = (int) ($row['row_count'] ?? 0);
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
        $storage = TaxonomyImagePathResolver::storagePayloadFromRecord('categories', $row);
        $row['set'] = (int) ($row['set'] ?? 0);
        $row['cover_image'] = $storage['cover_image'] ?? null;
        $row['preview_image'] = $storage['preview_image'] ?? null;
        $row['icon_image'] = $storage['icon_image'] ?? null;

        return array_merge(
            $row,
            TaxonomyImagePathResolver::pathsFromStoragePayload('categories', $id, $storage)
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

    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }
}
