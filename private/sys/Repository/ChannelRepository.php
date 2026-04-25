<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelRepository.php
 * Filesystem-backed channel metadata repository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Parser\ChannelContextParser;
use Raven\Lib\Parser\ChannelRepoParser;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Scribe\ChannelRecordScribe;
use Raven\Lib\Scribe\ChannelScribe;
use RuntimeException;

/**
 * Channel metadata is persisted as one PHP file per channel under `private/dat/channel/`.
 */
final class ChannelRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private string $channelDirectory;
    private ChannelContextParser $channelFileParser;
    private ChannelScribe $channelFileScribe;
    private ChannelRecordScribe $channelRecordScribe;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $channelsCache = null;

    public function __construct(PDO $db, string $driver, string $prefix, ?string $channelDirectory = null)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelDirectory = $channelDirectory ?? (dirname(__DIR__, 3) . '/dat/channel');
        $this->channelFileParser = new ChannelContextParser($this->channelDirectory);
        $this->channelFileScribe = new ChannelScribe($this->channelDirectory);
        $this->channelRecordScribe = new ChannelRecordScribe(
            $db,
            $driver,
            $prefix,
            $this->channelFileParser,
            $this->channelFileScribe
        );
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
            $aIsRoot = ChannelRepoParser::isRootChannelId((int) ($a['id'] ?? -1));
            $bIsRoot = ChannelRepoParser::isRootChannelId((int) ($b['id'] ?? -1));
            if ($aIsRoot !== $bIsRoot) {
                return $aIsRoot ? -1 : 1;
            }

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

        $this->channelRecordScribe->ensureRootChannelRecord();
        $this->channelFileScribe->normalizeStorageLayout();
        $paths = $this->channelFileParser->listChannelFilePaths();
        $records = [];
        $usedIds = [];
        $maxId = 0;
        $pendingRecords = [];

        foreach ($paths as $path) {
            $record = $this->channelFileParser->loadRecordFromPath($path);
            if ($record === null) {
                continue;
            }

            $rawId = $this->normalizeChannelId($record['id'] ?? null);
            if ($rawId !== null && !isset($usedIds[$rawId])) {
                $record['id'] = $rawId;
                $usedIds[$rawId] = true;
                if ($rawId > 0) {
                    $maxId = max($maxId, $rawId);
                }
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
                $this->channelRecordScribe->persistChannelId($slug, $id);
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
     * @return array<int, array{id: int, name: string, slug: string, category_sets: array<int, int|string>, tag_sets: array<int, int|string>, editor_override: string, route_mode: string, route_separator: string}>
     */
    public function listOptions(): array
    {
        $rows = [];
        foreach ($this->listRecords() as $channel) {
            if (ChannelRepoParser::isRootChannelId((int) ($channel['id'] ?? -1))) {
                continue;
            }

            $rows[] = [
                'id' => (int) ($channel['id'] ?? 0),
                'name' => (string) ($channel['name'] ?? ''),
                'slug' => (string) ($channel['slug'] ?? ''),
                'category_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($channel['category_sets'] ?? [], false),
                'tag_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($channel['tag_sets'] ?? [], false),
                'editor_override' => (string) ($channel['editor_override'] ?? 'inherit'),
                'route_mode' => (string) ($channel['route_mode'] ?? 'inherit'),
                'route_separator' => (string) ($channel['route_separator'] ?? 'inherit'),
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
     * Returns channel options for routing diagnostics, including the stock root channel.
     *
     * @return array<int, array{id: int, name: string, slug: string, feed_enabled: bool, category_sets: array<int, int|string>, tag_sets: array<int, int|string>, editor_override: string, route_mode: string, route_separator: string}>
     */
    public function listRoutingOptions(): array
    {
        $rows = [];
        foreach ($this->listRecords() as $channel) {
            $rows[] = [
                'id' => (int) ($channel['id'] ?? 0),
                'name' => (string) ($channel['name'] ?? ''),
                'slug' => (string) ($channel['slug'] ?? ''),
                'feed_enabled' => (bool) ($channel['feed_enabled'] ?? false),
                'category_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($channel['category_sets'] ?? [], false),
                'tag_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($channel['tag_sets'] ?? [], false),
                'editor_override' => (string) ($channel['editor_override'] ?? 'inherit'),
                'route_mode' => (string) ($channel['route_mode'] ?? 'inherit'),
                'route_separator' => (string) ($channel['route_separator'] ?? 'inherit'),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aIsRoot = ChannelRepoParser::isRootChannelId((int) ($a['id'] ?? -1));
            $bIsRoot = ChannelRepoParser::isRootChannelId((int) ($b['id'] ?? -1));
            if ($aIsRoot !== $bIsRoot) {
                return $aIsRoot ? -1 : 1;
            }

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
        if ($id < ChannelRepoParser::ROOT_CHANNEL_ID) {
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
     *   feed_enabled?: bool,
     *   category_sets?: array<int, int|string>,
     *   tag_sets?: array<int, int|string>,
     *   editor_override?: string,
     *   route_mode?: string,
     *   route_separator?: string
     * } $data
     */
    public function save(array $data): int
    {
        $channelId = $this->channelRecordScribe->save(
            $data,
            fn (string $slug): ?array => $this->findBySlug($slug),
            fn (int $id): ?array => $this->findById($id),
            fn (): int => $this->nextChannelId()
        );
        $this->channelsCache = null;
        return $channelId;
    }

    public function countExplicitTaxonomySetAssignments(string $kind, int $setId): int
    {
        $field = strtolower(trim($kind)) === 'tag' ? 'tag_sets' : 'category_sets';
        $count = 0;

        foreach ($this->listRecords() as $record) {
            $selection = ChannelRepoParser::normalizeTaxonomySetSelection($record[$field] ?? [], false);
            if (in_array($setId, $selection, true)) {
                $count++;
            }
        }

        return $count;
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
        $this->channelRecordScribe->updateImagePaths(
            $id,
            $paths,
            fn (int $channelId): ?array => $this->findById($channelId)
        );
        $this->channelsCache = null;
    }

    /**
     * Deletes one channel. Throws if the channel still has pages or redirects assigned.
     */
    public function deleteById(int $id): void
    {
        $this->channelRecordScribe->deleteById(
            $id,
            fn (int $channelId): ?array => $this->findById($channelId),
            fn (): array => $this->pageCountsByChannelId()
        );
        $this->channelsCache = null;
    }

    /**
     * @return array<int, int>
     */
    private function pageCountsByChannelId(): array
    {
        $pages = $this->table('pages');
        $stmt = $this->db->prepare(
            'SELECT channel AS resolved_channel_id, COUNT(*) AS page_count
             FROM ' . $pages . '
             GROUP BY channel'
        );
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $channelId = (int) ($row['resolved_channel_id'] ?? 0);
            $counts[$channelId] = (int) ($row['page_count'] ?? 0);
        }

        return $counts;
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
        return ChannelRepoParser::normalizeChannelId($value);
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
