<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelRepository.php
 * Filesystem-backed channel metadata repository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use Raven\Lib\Channel\ChannelFileStoreService;
use Raven\Lib\Database\Runtime\TableNameResolver;
use Raven\Lib\Routing\ChannelRecordPolicy;
use RuntimeException;

/**
 * Channel metadata is persisted as one PHP file per slug under `private/dat/channel/`.
 */
final class ChannelRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private string $channelDirectory;
    private ChannelFileStoreService $channelFileStoreService;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $channelsCache = null;

    public function __construct(PDO $db, string $driver, string $prefix, ?string $channelDirectory = null)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
        $this->channelDirectory = $channelDirectory ?? (dirname(__DIR__, 3) . '/dat/channel');
        $this->channelFileStoreService = new ChannelFileStoreService($this->channelDirectory);
    }

    /**
     * Returns all channels with attached page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $channels = $this->listRecords();
        $counts = $this->pageCountsByChannelId();
        foreach ($channels as $index => $channel) {
            $id = (int) ($channel['id'] ?? 0);
            $channels[$index]['page_count'] = (int) ($counts[$id] ?? 0);
        }

        usort($channels, static function (array $a, array $b): int {
            $nameCompare = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $channels;
    }

    /**
     * Returns all channel records without page-count joins.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRecords(): array
    {
        if (is_array($this->channelsCache)) {
            return $this->channelsCache;
        }

        $paths = $this->channelFileStoreService->listChannelFilePaths();
        $records = [];
        $usedIds = [];
        $maxId = 0;
        $pendingRecords = [];

        foreach ($paths as $path) {
            $basename = (string) basename($path);
            if ($basename === '' || !str_ends_with($basename, '.php')) {
                continue;
            }

            $slug = substr($basename, 0, -4);
            if ($slug === '' || !$this->isValidSlug($slug)) {
                continue;
            }

            $record = $this->channelFileStoreService->loadRecordFromPath($path, $slug);
            if ($record === null) {
                continue;
            }

            $rawId = $this->normalizeChannelId($record['id'] ?? null);
            if ($rawId !== null && !isset($usedIds[$rawId])) {
                $record['id'] = $rawId;
                $usedIds[$rawId] = true;
                $maxId = max($maxId, $rawId);
                $records[] = $record;
                continue;
            }

            $pendingRecords[] = [
                'path' => $path,
                'record' => $record,
            ];
        }

        foreach ($pendingRecords as $pending) {
            $id = $this->nextAvailableChannelId($usedIds, $maxId);
            $record = $pending['record'];
            if (!is_array($record)) {
                continue;
            }

            $record['id'] = $id;
            $records[] = $record;
            $slug = (string) ($record['slug'] ?? '');
            if ($slug !== '') {
                $this->persistChannelId($slug, $id);
            }
        }

        $this->channelsCache = $records;
        return $records;
    }

    /**
     * Resolves one channel id from existing records by slug.
     */
    public function idFromSlug(string $slug): int
    {
        return (int) ($this->idBySlug($slug) ?? 0);
    }

    /**
     * Resolves one channel id from existing records by slug.
     */
    public function idBySlug(string $slug): ?int
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->listRecords() as $channel) {
            if (strtolower((string) ($channel['slug'] ?? '')) !== $normalized) {
                continue;
            }

            return (int) ($channel['id'] ?? 0);
        }

        return null;
    }

    /**
     * Returns one total-count for panel channel index.
     */
    public function countForPanel(): int
    {
        return count($this->listRecords());
    }

    /**
     * Returns paginated channels with attached page counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->listAll();
        $safeOffset = max(0, $offset);
        $safeLimit = max(1, $limit);

        return array_values(array_slice($rows, $safeOffset, $safeLimit));
    }

    /**
     * Returns one paginated channel page plus total row count.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->listAll();
        $safeOffset = max(0, $offset);
        $safeLimit = max(1, $limit);

        return [
            'rows' => array_values(array_slice($rows, $safeOffset, $safeLimit)),
            'total' => count($rows),
        ];
    }

    /**
     * Returns minimal channel options for panel select controls.
     *
     * @return array<int, array{id: int, name: string, slug: string, text_editor_override: string, page_route_mode: string, page_url_separator: string}>
     */
    public function listOptions(): array
    {
        $rows = [];
        foreach ($this->listRecords() as $channel) {
            $rows[] = [
                'id' => (int) ($channel['id'] ?? 0),
                'name' => (string) ($channel['name'] ?? ''),
                'slug' => (string) ($channel['slug'] ?? ''),
                'text_editor_override' => (string) ($channel['text_editor_override'] ?? 'inherit'),
                'page_route_mode' => (string) ($channel['page_route_mode'] ?? 'slug'),
                'page_url_separator' => (string) ($channel['page_url_separator'] ?? 'inherit'),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $nameCompare = strcasecmp($a['name'], $b['name']);
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return $a['id'] <=> $b['id'];
        });

        return $rows;
    }

    /**
     * Returns true when one channel exists by slug.
     */
    public function slugExists(string $slug): bool
    {
        return $this->idBySlug($slug) !== null;
    }

    /**
     * Returns one channel by id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        foreach ($this->listRecords() as $channel) {
            if ((int) ($channel['id'] ?? 0) === $id) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Returns one channel by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->listRecords() as $channel) {
            if (strtolower((string) ($channel['slug'] ?? '')) === $normalized) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Creates or updates one channel and returns channel id.
     *
     * @param array{
     *   id: int|null,
     *   name: string,
     *   slug: string,
     *   description: string,
     *   text_editor_override?: string,
     *   page_route_mode?: string,
     *   page_url_separator?: string
     * } $data
     */
    public function save(array $data): int
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $name = trim((string) ($data['name'] ?? ''));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $textEditorOverride = $this->normalizeTextEditorOverride((string) ($data['text_editor_override'] ?? 'inherit'));
        $pageRouteMode = $this->normalizePageRouteMode((string) ($data['page_route_mode'] ?? 'slug'));
        $pageUrlSeparator = $this->normalizePageUrlSeparator((string) ($data['page_url_separator'] ?? 'inherit'));

        if ($name === '' || !$this->isValidSlug($slug)) {
            throw new RuntimeException('Channel name and slug are required.');
        }

        $existingBySlug = $this->findBySlug($slug);
        if ($existingBySlug !== null && (int) ($existingBySlug['id'] ?? 0) !== $id) {
            throw new RuntimeException('A channel with that slug already exists.');
        }

        $existingRecord = $id > 0 ? $this->findById($id) : null;
        $oldSlug = $existingRecord !== null ? (string) ($existingRecord['slug'] ?? '') : '';
        $channelId = $existingRecord !== null
            ? (int) ($existingRecord['id'] ?? 0)
            : $this->nextChannelId();

        $currentRaw = $oldSlug !== '' ? $this->channelFileStoreService->loadRawBySlug($oldSlug) : [];
        $customFields = is_array($currentRaw['custom_fields'] ?? null) ? $currentRaw['custom_fields'] : [];
        $overrides = is_array($currentRaw['overrides'] ?? null) ? $currentRaw['overrides'] : [];
        $createdAt = trim((string) ($currentRaw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => $channelId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'text_editor_override' => $textEditorOverride,
            'page_route_mode' => $pageRouteMode,
            'page_url_separator' => $pageUrlSeparator,
            'cover_image_path' => $this->normalizeNullablePath($currentRaw['cover_image_path'] ?? null),
            'cover_image_sm_path' => $this->normalizeNullablePath($currentRaw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => $this->normalizeNullablePath($currentRaw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => $this->normalizeNullablePath($currentRaw['cover_image_lg_path'] ?? null),
            'preview_image_path' => $this->normalizeNullablePath($currentRaw['preview_image_path'] ?? null),
            'preview_image_sm_path' => $this->normalizeNullablePath($currentRaw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => $this->normalizeNullablePath($currentRaw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => $this->normalizeNullablePath($currentRaw['preview_image_lg_path'] ?? null),
            'custom_fields' => $customFields,
            'overrides' => $overrides,
            'created_at' => $createdAt,
        ];

        $newPath = $this->channelFileStoreService->pathForSlug($slug);
        $oldPath = $oldSlug !== '' ? $this->channelFileStoreService->pathForSlug($oldSlug) : '';

        // For slug changes, write new file first, then remove old slug file.
        $this->channelFileStoreService->writeRecordAtPath($newPath, $record);

        if ($oldPath !== '' && $oldPath !== $newPath && is_file($oldPath)) {
            @unlink($oldPath);
        }

        $this->channelsCache = null;
        return $channelId;
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
        $record = $this->findById($id);
        if ($record === null) {
            throw new RuntimeException('Channel not found.');
        }

        $slug = (string) ($record['slug'] ?? '');
        if ($slug === '') {
            throw new RuntimeException('Channel slug is invalid.');
        }

        $raw = $this->channelFileStoreService->loadRawBySlug($slug);
        $raw['id'] = (int) ($record['id'] ?? $id);
        $raw['name'] = (string) ($record['name'] ?? '');
        $raw['slug'] = $slug;
        $raw['description'] = (string) ($record['description'] ?? '');
        $raw['text_editor_override'] = (string) ($record['text_editor_override'] ?? 'inherit');
        $raw['page_route_mode'] = (string) ($record['page_route_mode'] ?? 'slug');
        $raw['page_url_separator'] = (string) ($record['page_url_separator'] ?? 'inherit');
        $raw['cover_image_path'] = $this->normalizeNullablePath($paths['cover_image_path'] ?? null);
        $raw['cover_image_sm_path'] = $this->normalizeNullablePath($paths['cover_image_sm_path'] ?? null);
        $raw['cover_image_md_path'] = $this->normalizeNullablePath($paths['cover_image_md_path'] ?? null);
        $raw['cover_image_lg_path'] = $this->normalizeNullablePath($paths['cover_image_lg_path'] ?? null);
        $raw['preview_image_path'] = $this->normalizeNullablePath($paths['preview_image_path'] ?? null);
        $raw['preview_image_sm_path'] = $this->normalizeNullablePath($paths['preview_image_sm_path'] ?? null);
        $raw['preview_image_md_path'] = $this->normalizeNullablePath($paths['preview_image_md_path'] ?? null);
        $raw['preview_image_lg_path'] = $this->normalizeNullablePath($paths['preview_image_lg_path'] ?? null);
        $raw['custom_fields'] = is_array($raw['custom_fields'] ?? null) ? $raw['custom_fields'] : [];
        $raw['overrides'] = is_array($raw['overrides'] ?? null) ? $raw['overrides'] : [];
        $raw['created_at'] = trim((string) ($raw['created_at'] ?? '')) !== ''
            ? (string) $raw['created_at']
            : gmdate('Y-m-d H:i:s');

        $this->channelFileStoreService->writeRecordBySlug($slug, $raw);
        $this->channelsCache = null;
    }

    /**
     * Deletes one channel and detaches linked pages/redirects.
     */
    public function deleteById(int $id): void
    {
        $record = $this->findById($id);
        if ($record === null) {
            return;
        }

        $slug = (string) ($record['slug'] ?? '');
        $path = $slug !== '' ? $this->channelFileStoreService->pathForSlug($slug) : '';

        $pages = $this->table('pages');
        $redirects = $this->table('redirects');

        $this->db->beginTransaction();
        try {
            $detachPages = $this->db->prepare(
                'UPDATE ' . $pages . ' SET channel_id = :null_channel WHERE channel_id = :channel_id'
            );
            $detachPages->execute([
                ':null_channel' => null,
                ':channel_id' => $id,
            ]);

            $detachRedirects = $this->db->prepare(
                'UPDATE ' . $redirects . ' SET channel_id = :null_channel WHERE channel_id = :channel_id'
            );
            $detachRedirects->execute([
                ':null_channel' => null,
                ':channel_id' => $id,
            ]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        if ($path !== '' && is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Failed to delete channel file.');
        }

        $this->channelsCache = null;
    }

    /**
     * @return array<int, int>
     */
    private function pageCountsByChannelId(): array
    {
        $pages = $this->table('pages');
        $stmt = $this->db->prepare(
            'SELECT channel_id, COUNT(*) AS page_count
             FROM ' . $pages . '
             WHERE channel_id IS NOT NULL
             GROUP BY channel_id'
        );
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $channelId = (int) ($row['channel_id'] ?? 0);
            if ($channelId < 1) {
                continue;
            }

            $counts[$channelId] = (int) ($row['page_count'] ?? 0);
        }

        return $counts;
    }

    private function isValidSlug(string $slug): bool
    {
        return ChannelRecordPolicy::isValidSlug($slug);
    }

    private function normalizeTextEditorOverride(string $value): string
    {
        return ChannelRecordPolicy::normalizeTextEditorOverride($value);
    }

    private function normalizePageRouteMode(string $value): string
    {
        return ChannelRecordPolicy::normalizePageRouteMode($value);
    }

    private function normalizePageUrlSeparator(string $value): string
    {
        return ChannelRecordPolicy::normalizePageUrlSeparator($value);
    }

    private function normalizeNullablePath(mixed $value): ?string
    {
        return ChannelRecordPolicy::normalizeNullablePath($value);
    }

    /**
     * @param array<int, bool> $usedIds
     */
    private function nextAvailableChannelId(array &$usedIds, int &$maxId): int
    {
        $candidate = max(1, $maxId + 1);
        while (isset($usedIds[$candidate])) {
            $candidate++;
        }

        $usedIds[$candidate] = true;
        $maxId = max($maxId, $candidate);
        return $candidate;
    }

    private function nextChannelId(): int
    {
        $maxId = 0;
        foreach ($this->listRecords() as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId > $maxId) {
                $maxId = $recordId;
            }
        }

        return $maxId + 1;
    }

    private function normalizeChannelId(mixed $value): ?int
    {
        return ChannelRecordPolicy::normalizeChannelId($value);
    }

    private function persistChannelId(string $slug, int $id): void
    {
        try {
            $this->channelFileStoreService->persistChannelId($slug, $id);
        } catch (\Throwable) {
            // Keep repository reads resilient even when id backfill cannot be persisted.
        }
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
