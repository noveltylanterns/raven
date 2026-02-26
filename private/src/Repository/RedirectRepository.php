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
     * Returns one total-count for panel redirect index.
     */
    public function countForPanel(): int
    {
        $redirects = $this->table('redirects');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $redirects);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated redirects with optional linked channel metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $redirects = $this->table('redirects');
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel_id, r.is_active, r.target_url, r.created_at, r.updated_at,
                    c.slug AS channel_slug, c.name AS channel_name
             FROM ' . $redirects . ' r
             LEFT JOIN ' . $channels . ' c ON c.id = r.channel_id
             ORDER BY r.updated_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one paginated redirect page plus total row count in one query.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        $redirects = $this->table('redirects');
        $channels = $this->table('channels');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.title,
                    page_rows.description,
                    page_rows.slug,
                    page_rows.channel_id,
                    page_rows.is_active,
                    page_rows.target_url,
                    page_rows.created_at,
                    page_rows.updated_at,
                    page_rows.channel_slug,
                    page_rows.channel_name,
                    totals.total_rows
             FROM (
                 SELECT r.id, r.title, r.description, r.slug, r.channel_id, r.is_active, r.target_url, r.created_at, r.updated_at,
                        c.slug AS channel_slug, c.name AS channel_name
                 FROM ' . $redirects . ' r
                 LEFT JOIN ' . $channels . ' c ON c.id = r.channel_id
                 ORDER BY r.updated_at DESC, r.id DESC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $redirects . '
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
     * Returns redirect-editor data (optional redirect row + channel options) in one query.
     *
     * @return array{
     *   redirect: array<string, mixed>|null,
     *   channels: array<int, array{id: int, name: string, slug: string}>
     * }
     */
    public function editFormData(?int $id = null): array
    {
        $channels = $this->table('channels');
        $redirects = $this->table('redirects');

        $sql = 'SELECT row_type,
                       option_id,
                       option_name,
                       option_slug,
                       redirect_id,
                       redirect_title,
                       redirect_description,
                       redirect_slug,
                       redirect_channel_id,
                       redirect_is_active,
                       redirect_target_url,
                       redirect_created_at,
                       redirect_updated_at,
                       redirect_channel_slug,
                       redirect_channel_name
                FROM (
                    SELECT
                        \'channel\' AS row_type,
                        c.id AS option_id,
                        c.name AS option_name,
                        c.slug AS option_slug,
                        NULL AS redirect_id,
                        NULL AS redirect_title,
                        NULL AS redirect_description,
                        NULL AS redirect_slug,
                        NULL AS redirect_channel_id,
                        NULL AS redirect_is_active,
                        NULL AS redirect_target_url,
                        NULL AS redirect_created_at,
                        NULL AS redirect_updated_at,
                        NULL AS redirect_channel_slug,
                        NULL AS redirect_channel_name
                    FROM ' . $channels . ' c
                    UNION ALL
                    SELECT
                        \'redirect\' AS row_type,
                        NULL AS option_id,
                        NULL AS option_name,
                        NULL AS option_slug,
                        r.id AS redirect_id,
                        r.title AS redirect_title,
                        r.description AS redirect_description,
                        r.slug AS redirect_slug,
                        r.channel_id AS redirect_channel_id,
                        r.is_active AS redirect_is_active,
                        r.target_url AS redirect_target_url,
                        r.created_at AS redirect_created_at,
                        r.updated_at AS redirect_updated_at,
                        rc.slug AS redirect_channel_slug,
                        rc.name AS redirect_channel_name
                    FROM ' . $redirects . ' r
                    LEFT JOIN ' . $channels . ' rc ON rc.id = r.channel_id
                    WHERE r.id = :id
                ) editor_rows
                ORDER BY
                    CASE WHEN row_type = \'channel\' THEN 0 ELSE 1 END,
                    option_name ASC,
                    option_id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id !== null && $id > 0 ? $id : 0]);
        $rows = $stmt->fetchAll() ?: [];

        $channelOptions = [];
        $redirectRow = null;
        foreach ($rows as $row) {
            $rowType = strtolower(trim((string) ($row['row_type'] ?? '')));
            if ($rowType === 'channel') {
                $optionId = (int) ($row['option_id'] ?? 0);
                $optionSlug = (string) ($row['option_slug'] ?? '');
                if ($optionId > 0 && $optionSlug !== '') {
                    $channelOptions[] = [
                        'id' => $optionId,
                        'name' => (string) ($row['option_name'] ?? ''),
                        'slug' => $optionSlug,
                    ];
                }
                continue;
            }

            if ($rowType === 'redirect' && $redirectRow === null) {
                $redirectId = (int) ($row['redirect_id'] ?? 0);
                if ($redirectId > 0) {
                    $redirectRow = [
                        'id' => $redirectId,
                        'title' => (string) ($row['redirect_title'] ?? ''),
                        'description' => (string) ($row['redirect_description'] ?? ''),
                        'slug' => (string) ($row['redirect_slug'] ?? ''),
                        'channel_id' => $row['redirect_channel_id'] !== null ? (int) $row['redirect_channel_id'] : null,
                        'is_active' => (int) ($row['redirect_is_active'] ?? 0),
                        'target_url' => (string) ($row['redirect_target_url'] ?? ''),
                        'created_at' => (string) ($row['redirect_created_at'] ?? ''),
                        'updated_at' => (string) ($row['redirect_updated_at'] ?? ''),
                        'channel_slug' => (string) ($row['redirect_channel_slug'] ?? ''),
                        'channel_name' => (string) ($row['redirect_channel_name'] ?? ''),
                    ];
                }
            }
        }

        return [
            'redirect' => $redirectRow,
            'channels' => $channelOptions,
        ];
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
