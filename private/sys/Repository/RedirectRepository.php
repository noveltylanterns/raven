<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/RedirectRepository.php
 * Repository for panel-managed URL redirect records.
 * Docs: https://raven.lanterns.io
 */

// Inline note: RedirectRepository keeps redirect storage rules in one place.

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Channel\ChannelContextService;
use Raven\Lib\Channel\ChannelRecordPolicy;
use Raven\Lib\Database\Runtime\TableNameResolver;
use Raven\Lib\Routing\PathScopeLookupService;
use RuntimeException;

/**
 * Data access for Redirect CRUD operations and public redirect lookups.
 */
final class RedirectRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRepository $channelRepo;

    public function __construct(PDO $db, string $driver, string $prefix, ChannelRepository $channelRepo)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelRepo = $channelRepo;
    }

    /**
     * Returns all redirects with optional linked channel metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $redirects = $this->table('redirects');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
             FROM ' . $redirects . ' r
             ORDER BY r.updated DESC, r.id DESC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext($row, $channelsById);
        }

        return $rows;
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

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
             FROM ' . $redirects . ' r
             ORDER BY r.updated DESC, r.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext($row, $channelsById);
        }

        return $rows;
    }

    /**
     * Returns one paginated redirect page plus total row count in one query.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        $redirects = $this->table('redirects');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.title,
                    page_rows.description,
                    page_rows.slug,
                    page_rows.channel,
                    page_rows.active,
                    page_rows.target,
                    page_rows.created,
                    page_rows.updated,
                    totals.total_rows
             FROM (
                 SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
                 FROM ' . $redirects . ' r
                 ORDER BY r.updated DESC, r.id DESC
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
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->withChannelContext($row, $channelsById);
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

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
             FROM ' . $redirects . ' r
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($row, $this->channelsByIdMap());
    }

    /**
     * Returns one redirect by slug and optional channel scope.
     *
     * $channel accepts a channel ID (int), a channel slug (string), or null for root scope.
     * Root scope matches redirects that do not belong to any channel.
     * Matches regardless of active status — use findActiveByPath for public routing.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, int|string|null $channel = null): ?array
    {
        $redirects = $this->table('redirects');
        $sql = 'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
                FROM ' . $redirects . ' r
                WHERE r.slug = :slug';
        $params = [':slug' => $slug];

        if (is_string($channel)) {
            $channelId = $this->channelRepo->idFromSlug($channel);
            if ($channelId < 1) {
                return null;
            }
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channelId;
        } elseif (is_int($channel) && $channel > 0) {
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channel;
        } else {
            // null or 0 = root scope
            $sql .= ' AND r.channel = 0';
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();

        return $row === false ? null : $this->withChannelContext($row, $this->channelsByIdMap());
    }

    /**
     * Returns one redirect id by slug and optional channel scope, or null when not found.
     *
     * $channel accepts a channel ID (int), a channel slug (string), or null for root scope.
     */
    public function idBySlug(string $slug, int|string|null $channel = null): ?int
    {
        $redirects = $this->table('redirects');
        $sql = 'SELECT r.id FROM ' . $redirects . ' r WHERE r.slug = :slug';
        $params = [':slug' => $slug];

        if (is_string($channel)) {
            $channelId = $this->channelRepo->idFromSlug($channel);
            if ($channelId < 1) {
                return null;
            }
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channelId;
        } elseif (is_int($channel) && $channel > 0) {
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channel;
        } else {
            $sql .= ' AND r.channel = 0';
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Returns redirect-editor data (optional redirect row + channel options) in one query.
     *
     * @return array{
     *   redirect: array<string, mixed>|null,
     *   channel_options: array<int, array{id: int, name: string, slug: string}>
     * }
     */
    public function editFormData(?int $id = null): array
    {
        $redirects = $this->table('redirects');
        $channelOptions = $this->channelRepo->listOptions();
        $redirectRow = null;
        $normalizedId = $id !== null && $id > 0 ? $id : 0;
        if ($normalizedId > 0) {
            $stmt = $this->db->prepare(
                'SELECT id, title, description, slug, channel, active, target, created, updated
                 FROM ' . $redirects . '
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $normalizedId]);
            $row = $stmt->fetch();
            if ($row !== false && is_array($row)) {
                $redirectRow = $this->withChannelContext($row, $this->channelsByIdMap());
            }
        }

        return [
            'redirect' => $redirectRow,
            'channel_options' => $channelOptions,
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

        $sql = 'SELECT r.id, r.title, r.slug, r.channel, r.target, r.active
                FROM ' . $redirects . ' r
                WHERE r.slug = :slug
                  AND r.active = :active';
        $params = [
            ':slug' => $slug,
            ':active' => 1,
        ];

        // Root redirects match only channelless rows; channel routes must match channel slug.
        if ($channelSlug === null) {
            $sql .= ' AND r.channel = 0';
        } else {
            try {
                $channelId = $this->channelIdBySlug($channelSlug);
            } catch (RuntimeException) {
                return null;
            }
            if ($channelId === null || $channelId < 1) {
                return null;
            }
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channelId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($row, $this->channelsByIdMap());
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
     *   active: int,
     *   target: string
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
        $isActive = (int) ($data['active'] ?? 0) === 1 ? 1 : 0;
        $targetUrl = trim((string) ($data['target'] ?? ''));
        $channelId = $this->channelIdBySlug($channelSlug) ?? 0;
        if (trim((string) ($channelSlug ?? '')) !== '' && $channelId < 1) {
            throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
        }
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
                     channel = :channel,
                     active = :active,
                     target = :target,
                     updated = :updated
                 WHERE id = :id'
            );
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':slug' => $slug,
                ':channel' => $channelId,
                ':active' => $isActive,
                ':target' => $targetUrl,
                ':updated' => $now,
                ':id' => $id,
            ]);

            return $id;
        }

        // Insert path stores creation/update timestamps together.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $redirects . '
             (title, description, slug, channel, active, target, created, updated)
             VALUES (:title, :description, :slug, :channel, :active, :target, :created, :updated)'
        );
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':slug' => $slug,
            ':channel' => $channelId,
            ':active' => $isActive,
            ':target' => $targetUrl,
            ':created' => $now,
            ':updated' => $now,
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
        return PathScopeLookupService::exists(
            $this->db,
            $this->table('redirects'),
            $slug,
            $channelId,
            $ignoreId,
            'ignore_id',
            'channel'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function channelsByIdMap(): array
    {
        return ChannelContextService::channelsByIdMap($this->channelRepo->listRecords());
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $channelsById
     * @return array<string, mixed>
     */
    private function withChannelContext(array $row, array $channelsById): array
    {
        $channelId = (int) ($row['channel'] ?? 0);
        $channel = $channelId > 0 ? ($channelsById[$channelId] ?? null) : null;
        $row['channel'] = $channelId;
        $row['active'] = (int) ($row['active'] ?? 0);
        $row['target'] = (string) ($row['target'] ?? '');
        $row['created'] = (string) ($row['created'] ?? '');
        $row['updated'] = (string) ($row['updated'] ?? '');

        return ChannelContextService::applyBasicChannelContext($row, $channel);
    }

    /**
     * Resolves channel id by slug for channel-bound redirects.
     */
    private function channelIdBySlug(?string $slug): ?int
    {
        if (ChannelRecordPolicy::isRootChannelSlug((string) ($slug ?? ''))) {
            return 0;
        }

        return ChannelContextService::resolveChannelIdBySlug(
            $slug,
            fn (string $normalized): ?int => $this->channelRepo->idBySlug($normalized),
            'Selected channel does not exist.'
        );
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
