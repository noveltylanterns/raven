<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/RedirectRepository.php
 * Repository for panel-managed URL redirect records.
 * Docs: https://raven.lanterns.io
 */

// Inline note: RedirectRepository keeps redirect storage rules in one place.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use RuntimeException;

/**
 * Data access for Redirect CRUD operations and public redirect lookups.
 */
final class RedirectRepository
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
     * Returns all redirects with optional linked channel metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $redirects = $this->table('redirects');
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel_id, r.is_active, r.target_url, r.created_at, r.updated_at,
                    c.slug AS channel_slug, c.name AS channel_name
             FROM ' . $redirects . ' r
             LEFT JOIN ' . $channels . ' c ON c.id = r.channel_id
             ORDER BY r.updated_at DESC, r.id DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one redirect row by id for panel edit form.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $redirects = $this->table('redirects');
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel_id, r.is_active, r.target_url, r.created_at, r.updated_at,
                    c.slug AS channel_slug, c.name AS channel_name
             FROM ' . $redirects . ' r
             LEFT JOIN ' . $channels . ' c ON c.id = r.channel_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Resolves one active redirect for public URL matching.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByPath(string $slug, ?string $channelSlug = null): ?array
    {
        $redirects = $this->table('redirects');
        $channels = $this->table('channels');

        $sql = 'SELECT r.id, r.title, r.slug, r.channel_id, r.target_url, r.is_active, c.slug AS channel_slug
                FROM ' . $redirects . ' r
                LEFT JOIN ' . $channels . ' c ON c.id = r.channel_id
                WHERE r.slug = :slug
                  AND r.is_active = :is_active';
        $params = [
            ':slug' => $slug,
            ':is_active' => 1,
        ];

        // Root redirects match only channelless rows; channel routes must match channel slug.
        if ($channelSlug === null) {
            $sql .= ' AND r.channel_id IS NULL';
        } else {
            $sql .= ' AND c.slug = :channel_slug';
            $params[':channel_slug'] = $channelSlug;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Creates or updates one redirect and returns redirect id.
     *
     * @param array{
     *   id: int|null,
     *   title: string,
     *   description: string,
     *   slug: string,
     *   channel_slug: string|null,
     *   is_active: int,
     *   target_url: string
     * } $data
     */
    public function save(array $data): int
    {
        $redirects = $this->table('redirects');

        $id = $data['id'] ?? null;
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        $channelSlug = $data['channel_slug'] ?? null;
        $isActive = (int) ($data['is_active'] ?? 0) === 1 ? 1 : 0;
        $targetUrl = trim((string) ($data['target_url'] ?? ''));
        $channelId = $this->channelIdBySlug($channelSlug);
        $now = gmdate('Y-m-d H:i:s');

        if ($title === '' || $slug === '' || $targetUrl === '') {
            throw new RuntimeException('Redirect title, slug, and target URL are required.');
        }

        // Path uniqueness is scoped to (channel, slug) pairs.
        if ($this->pathExists($slug, $channelId, $id)) {
            throw new RuntimeException('A redirect already exists for that slug/channel path.');
        }

        if ($id !== null && $id > 0) {
            // Update in place when editing an existing redirect.
            $stmt = $this->db->prepare(
                'UPDATE ' . $redirects . '
                 SET title = :title,
                     description = :description,
                     slug = :slug,
                     channel_id = :channel_id,
                     is_active = :is_active,
                     target_url = :target_url,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':slug' => $slug,
                ':channel_id' => $channelId,
                ':is_active' => $isActive,
                ':target_url' => $targetUrl,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            return $id;
        }

        // Insert path stores creation/update timestamps together.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $redirects . '
             (title, description, slug, channel_id, is_active, target_url, created_at, updated_at)
             VALUES (:title, :description, :slug, :channel_id, :is_active, :target_url, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':slug' => $slug,
            ':channel_id' => $channelId,
            ':is_active' => $isActive,
            ':target_url' => $targetUrl,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deletes one redirect by id.
     */
    public function deleteById(int $id): void
    {
        $redirects = $this->table('redirects');

        $stmt = $this->db->prepare('DELETE FROM ' . $redirects . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Checks whether another redirect already uses one (channel, slug) path.
     */
    private function pathExists(string $slug, ?int $channelId, ?int $ignoreId = null): bool
    {
        $redirects = $this->table('redirects');

        $sql = 'SELECT 1
                FROM ' . $redirects . '
                WHERE slug = :slug';
        $params = [
            ':slug' => $slug,
        ];

        if ($channelId === null) {
            $sql .= ' AND channel_id IS NULL';
        } else {
            $sql .= ' AND channel_id = :channel_id';
            $params[':channel_id'] = $channelId;
        }

        if ($ignoreId !== null && $ignoreId > 0) {
            $sql .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Resolves channel id by slug for channel-bound redirects.
     */
    private function channelIdBySlug(?string $slug): ?int
    {
        $slug = trim((string) ($slug ?? ''));
        if ($slug === '') {
            return null;
        }

        $channels = $this->table('channels');
        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $channels . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $channelId = $stmt->fetchColumn();
        if ($channelId === false) {
            throw new RuntimeException('Selected channel does not exist.');
        }

        return (int) $channelId;
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
            'redirects' => 'taxonomy.redirects',
            default => 'main.' . $table,
        };
    }
}
