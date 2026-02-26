<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/CategoryRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;

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
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
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

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, c.description, c.created_at,
                    c.cover_image_path, c.cover_image_sm_path, c.cover_image_md_path, c.cover_image_lg_path,
                    c.preview_image_path, c.preview_image_sm_path, c.preview_image_md_path, c.preview_image_lg_path,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $categories . ' c
             LEFT JOIN (
                 SELECT category_id, COUNT(*) AS page_count
                 FROM ' . $pageCategories . '
                 GROUP BY category_id
             ) pc ON pc.category_id = c.id
             ORDER BY c.name ASC, c.id ASC'
        );
        // LEFT JOIN keeps categories with zero linked pages visible in admin listings.
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one total-count for panel category index.
     */
    public function countForPanel(): int
    {
        $categories = $this->table('categories');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $categories);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated categories with linked page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, c.description, c.created_at,
                    c.cover_image_path, c.cover_image_sm_path, c.cover_image_md_path, c.cover_image_lg_path,
                    c.preview_image_path, c.preview_image_sm_path, c.preview_image_md_path, c.preview_image_lg_path,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $categories . ' c
             LEFT JOIN (
                 SELECT category_id, COUNT(*) AS page_count
                 FROM ' . $pageCategories . '
                 GROUP BY category_id
             ) pc ON pc.category_id = c.id
             ORDER BY c.name ASC, c.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns minimal category options for panel select controls.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function listOptions(): array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug
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
            'SELECT id, name, slug, description, created_at,
                    cover_image_path, cover_image_sm_path, cover_image_md_path, cover_image_lg_path,
                    preview_image_path, preview_image_sm_path, preview_image_md_path, preview_image_lg_path
             FROM ' . $categories . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates or updates one category and returns category id.
     *
     * @param array{id: int|null, name: string, slug: string, description: string} $data
     */
    public function save(array $data): int
    {
        $categories = $this->table('categories');

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $slug = $data['slug'];
        $description = $data['description'];

        if ($id !== null && $id > 0) {
            // Update existing row when an id is present.
            $stmt = $this->db->prepare(
                'UPDATE ' . $categories . '
                 SET name = :name,
                     slug = :slug,
                     description = :description
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':description' => $description,
                ':id' => $id,
            ]);

            return $id;
        }

        // Insert path creates a new category with creation timestamp.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $categories . ' (name, slug, description, created_at)
             VALUES (:name, :slug, :description, :created_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates one category's cover/preview image path set.
     *
     * @param array{
     *   cover_image_path: string|null,
     *   cover_image_sm_path: string|null,
     *   cover_image_md_path: string|null,
     *   cover_image_lg_path: string|null,
     *   preview_image_path: string|null,
     *   preview_image_sm_path: string|null,
     *   preview_image_md_path: string|null,
     *   preview_image_lg_path: string|null
     * } $paths
     */
    public function updateImagePaths(int $id, array $paths): void
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'UPDATE ' . $categories . '
             SET cover_image_path = :cover_image_path,
                 cover_image_sm_path = :cover_image_sm_path,
                 cover_image_md_path = :cover_image_md_path,
                 cover_image_lg_path = :cover_image_lg_path,
                 preview_image_path = :preview_image_path,
                 preview_image_sm_path = :preview_image_sm_path,
                 preview_image_md_path = :preview_image_md_path,
                 preview_image_lg_path = :preview_image_lg_path
             WHERE id = :id'
        );
        $stmt->execute([
            ':cover_image_path' => $paths['cover_image_path'] ?? null,
            ':cover_image_sm_path' => $paths['cover_image_sm_path'] ?? null,
            ':cover_image_md_path' => $paths['cover_image_md_path'] ?? null,
            ':cover_image_lg_path' => $paths['cover_image_lg_path'] ?? null,
            ':preview_image_path' => $paths['preview_image_path'] ?? null,
            ':preview_image_sm_path' => $paths['preview_image_sm_path'] ?? null,
            ':preview_image_md_path' => $paths['preview_image_md_path'] ?? null,
            ':preview_image_lg_path' => $paths['preview_image_lg_path'] ?? null,
            ':id' => $id,
        ]);
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
                'DELETE FROM ' . $pageCategories . ' WHERE category_id = :category_id'
            );
            $detach->execute([':category_id' => $id]);

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
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        if ($this->driver !== 'sqlite') {
            // Shared-db mode: physical name is prefix + logical table.
            return $this->prefix . $table;
        }

        // SQLite mode: resolve to attached database aliases.
        return match ($table) {
            'categories' => 'taxonomy.categories',
            'page_categories' => 'main.page_categories',
            default => 'main.' . $table,
        };
    }
}
