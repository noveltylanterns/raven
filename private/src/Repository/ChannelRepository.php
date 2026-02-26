<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/ChannelRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;

/**
 * Data access for Channel CRUD operations in panel.
 */
final class ChannelRepository
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
     * Returns all channels with attached page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $channels = $this->table('channels');
        $pages = $this->table('pages');

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, c.description, c.created_at,
                    c.cover_image_path, c.cover_image_sm_path, c.cover_image_md_path, c.cover_image_lg_path,
                    c.preview_image_path, c.preview_image_sm_path, c.preview_image_md_path, c.preview_image_lg_path,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $channels . ' c
             LEFT JOIN (
                 SELECT channel_id, COUNT(*) AS page_count
                 FROM ' . $pages . '
                 WHERE channel_id IS NOT NULL
                 GROUP BY channel_id
             ) pc ON pc.channel_id = c.id
             ORDER BY c.name ASC, c.id ASC'
        );
        // LEFT JOIN keeps channels with zero pages visible in admin listings.
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one total-count for panel channel index.
     */
    public function countForPanel(): int
    {
        $channels = $this->table('channels');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $channels);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated channels with attached page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $channels = $this->table('channels');
        $pages = $this->table('pages');

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, c.description, c.created_at,
                    c.cover_image_path, c.cover_image_sm_path, c.cover_image_md_path, c.cover_image_lg_path,
                    c.preview_image_path, c.preview_image_sm_path, c.preview_image_md_path, c.preview_image_lg_path,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $channels . ' c
             LEFT JOIN (
                 SELECT channel_id, COUNT(*) AS page_count
                 FROM ' . $pages . '
                 WHERE channel_id IS NOT NULL
                 GROUP BY channel_id
             ) pc ON pc.channel_id = c.id
             ORDER BY c.name ASC, c.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns minimal channel options for panel select controls.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function listOptions(): array
    {
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug
             FROM ' . $channels . '
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
     * Returns true when one channel exists by slug.
     */
    public function slugExists(string $slug): bool
    {
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM ' . $channels . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns one channel by id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, description, created_at,
                    cover_image_path, cover_image_sm_path, cover_image_md_path, cover_image_lg_path,
                    preview_image_path, preview_image_sm_path, preview_image_md_path, preview_image_lg_path
             FROM ' . $channels . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates or updates one channel and returns channel id.
     *
     * @param array{id: int|null, name: string, slug: string, description: string} $data
     */
    public function save(array $data): int
    {
        $channels = $this->table('channels');

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $slug = $data['slug'];
        $description = $data['description'];

        if ($id !== null && $id > 0) {
            // Update existing row when an id is present.
            $stmt = $this->db->prepare(
                'UPDATE ' . $channels . '
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

        // Insert path creates a new channel with creation timestamp.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $channels . ' (name, slug, description, created_at)
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
     * Updates one channel's cover/preview image path set.
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
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'UPDATE ' . $channels . '
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
     * Deletes one channel and detaches linked pages (channel_id -> NULL).
     */
    public function deleteById(int $id): void
    {
        $channels = $this->table('channels');
        $pages = $this->table('pages');
        $redirects = $this->table('redirects');

        $this->db->beginTransaction();

        try {
            // Keep pages alive when a channel is removed.
            $detach = $this->db->prepare(
                'UPDATE ' . $pages . ' SET channel_id = :null_channel WHERE channel_id = :channel_id'
            );
            $detach->execute([
                ':null_channel' => null,
                ':channel_id' => $id,
            ]);

            // Keep channel-bound redirects alive by converting them to root-level redirects.
            $detachRedirects = $this->db->prepare(
                'UPDATE ' . $redirects . ' SET channel_id = :null_channel WHERE channel_id = :channel_id'
            );
            $detachRedirects->execute([
                ':null_channel' => null,
                ':channel_id' => $id,
            ]);

            $delete = $this->db->prepare('DELETE FROM ' . $channels . ' WHERE id = :id');
            $delete->execute([':id' => $id]);

            // Commit only after both detach and delete succeed.
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
            'channels' => 'taxonomy.channels',
            'pages' => 'main.pages',
            'redirects' => 'taxonomy.redirects',
            default => 'main.' . $table,
        };
    }
}
