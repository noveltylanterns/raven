<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/TagRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;

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
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
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
            'SELECT t.id, t.name, t.slug, t.description, t.created_at,
                    t.cover_image_path, t.cover_image_sm_path, t.cover_image_md_path, t.cover_image_lg_path,
                    t.preview_image_path, t.preview_image_sm_path, t.preview_image_md_path, t.preview_image_lg_path,
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

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one total-count for panel tag index.
     */
    public function countForPanel(): int
    {
        $tags = $this->table('tags');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $tags);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated tags with linked page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.slug, t.description, t.created_at,
                    t.cover_image_path, t.cover_image_sm_path, t.cover_image_md_path, t.cover_image_lg_path,
                    t.preview_image_path, t.preview_image_sm_path, t.preview_image_md_path, t.preview_image_lg_path,
                    COALESCE(pt.page_count, 0) AS page_count
             FROM ' . $tags . ' t
             LEFT JOIN (
                 SELECT tag_id, COUNT(*) AS page_count
                 FROM ' . $pageTags . '
                 GROUP BY tag_id
             ) pt ON pt.tag_id = t.id
             ORDER BY t.name ASC, t.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one paginated tag page plus total row count in one query.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.name,
                    page_rows.slug,
                    page_rows.description,
                    page_rows.created_at,
                    page_rows.cover_image_path,
                    page_rows.cover_image_sm_path,
                    page_rows.cover_image_md_path,
                    page_rows.cover_image_lg_path,
                    page_rows.preview_image_path,
                    page_rows.preview_image_sm_path,
                    page_rows.preview_image_md_path,
                    page_rows.preview_image_lg_path,
                    page_rows.page_count,
                    totals.total_rows
             FROM (
                 SELECT t.id, t.name, t.slug, t.description, t.created_at,
                        t.cover_image_path, t.cover_image_sm_path, t.cover_image_md_path, t.cover_image_lg_path,
                        t.preview_image_path, t.preview_image_sm_path, t.preview_image_md_path, t.preview_image_lg_path,
                        COALESCE(pt.page_count, 0) AS page_count
                 FROM ' . $tags . ' t
                 LEFT JOIN (
                     SELECT tag_id, COUNT(*) AS page_count
                     FROM ' . $pageTags . '
                     GROUP BY tag_id
                 ) pt ON pt.tag_id = t.id
                 ORDER BY t.name ASC, t.id ASC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $tags . '
             ) AS totals'
        );
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
            $resultRows[] = $row;
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->countForPanel();
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns minimal tag options for panel select controls.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function listOptions(): array
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug
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
            'SELECT id, name, slug, description, created_at,
                    cover_image_path, cover_image_sm_path, cover_image_md_path, cover_image_lg_path,
                    preview_image_path, preview_image_sm_path, preview_image_md_path, preview_image_lg_path
             FROM ' . $tags . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates or updates one tag and returns tag id.
     *
     * @param array{id: int|null, name: string, slug: string, description: string} $data
     */
    public function save(array $data): int
    {
        $tags = $this->table('tags');

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $slug = $data['slug'];
        $description = $data['description'];

        if ($id !== null && $id > 0) {
            // Update existing row when an id is present.
            $stmt = $this->db->prepare(
                'UPDATE ' . $tags . '
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

        // Insert path creates a new tag with creation timestamp.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $tags . ' (name, slug, description, created_at)
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
     * Updates one tag's cover/preview image path set.
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
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'UPDATE ' . $tags . '
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
            'tags' => 'taxonomy.tags',
            'page_tags' => 'main.page_tags',
            default => 'main.' . $table,
        };
    }
}
