<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelRead.php
 * Read-only data access for filesystem-backed channel metadata.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Parser\ChannelRepoParser;
use Raven\Lib\Scribe\ChannelScribe;

/**
 * SELECT and lookup methods for channel records.
 *
 * Channel metadata is persisted as one PHP file per channel under `private/dat/channel/`.
 * This repository owns the file-backed read helpers directly so channel storage reads stay
 * attached to the repository seam instead of leaking through a separate parser wrapper.
 * Write operations (save, delete, image updates) live in ChannelWrite. The in-process record
 * cache lives here; ChannelWrite calls clearCache() after mutations.
 */
class ChannelRead
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private string $channelDirectory;
    private ChannelScribe $channelFileScribe;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $channelsCache = null;

    /**
     * @param PDO         $db               Active database connection.
     * @param string      $driver           Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix           Table name prefix for this Raven installation.
     * @param string|null $channelDirectory Absolute path to the channel file directory; defaults to private/dat/channel.
     */
    public function __construct(PDO $db, string $driver, string $prefix, ?string $channelDirectory = null)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelDirectory = rtrim($channelDirectory ?? (dirname(__DIR__, 3) . '/dat/channel'), '/');
        $this->channelFileScribe = new ChannelScribe($this->channelDirectory);
    }

    /**
     * Returns all channels with attached page counts, sorted with the root channel last.
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
                // Root channel sorts last in listings.
                return $aIsRoot ? 1 : -1;
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
     * Maintains an in-process cache; call clearCache() after any write to invalidate it.
     * Also auto-repairs missing root records and normalizes the storage layout on first load.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRecords(): array
    {
        if (is_array($this->channelsCache)) {
            return $this->channelsCache;
        }

        $this->ensureRootChannelRecord();
        $this->channelFileScribe->normalizeStorageLayout();
        $paths = $this->listChannelFilePaths();
        $records = [];
        $usedIds = [];
        $maxId = 0;
        $pendingRecords = [];

        foreach ($paths as $path) {
            $record = $this->loadRecordFromPath($path);
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

            // Id collision or missing id: assign a fresh one after the first pass.
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
                try {
                    $this->channelFileScribe->persistChannelId($slug, $id);
                } catch (\Throwable) {
                    // Read paths should stay resilient even if best-effort id repair cannot be persisted.
                }
            }
        }

        $this->channelsCache = $records;
        return $records;
    }

    /**
     * Clears the in-process record cache.
     *
     * Must be called by ChannelWrite after any mutation so subsequent reads
     * reflect the new state from disk.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->channelsCache = null;
    }

    /**
     * Resolves one channel id by slug, returning 0 when not found.
     *
     * @param string $slug Slug to resolve.
     * @return int Channel id, or 0 when no matching channel exists.
     */
    public function idFromSlug(string $slug): int
    {
        return (int) ($this->idBySlug($slug) ?? 0);
    }

    /**
     * Resolves one channel id by slug, or null when not found.
     *
     * @param string $slug Slug to resolve.
     * @return int|null Channel id, or null when no matching channel exists.
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
     * Returns total channel count including the root channel record.
     *
     * @return int Total channel count.
     */
    public function count(): int
    {
        return count($this->listRecords());
    }

    /**
     * Returns paginated channels with attached page counts.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array<int, array<string, mixed>> Hydrated channel rows with page counts.
     */
    public function listPaged(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->listAll();
        $safeOffset = max(0, $offset);
        $safeLimit = max(1, $limit);

        return array_values(array_slice($rows, $safeOffset, $safeLimit));
    }

    /**
     * Returns one paginated channel page plus total row count.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0): array
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
     * Returns minimal channel option rows suitable for select controls and parser lookups.
     *
     * Excludes the root channel, which is not selectable as a page destination.
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
     * Returns true when a channel with the given slug exists.
     *
     * @param string $slug Normalized slug to check.
     * @return bool True when a channel with this slug is found.
     */
    public function slugExists(string $slug): bool
    {
        return $this->idBySlug($slug) !== null;
    }

    /**
     * Returns one channel by id.
     *
     * @param int $id Channel id to resolve; must be >= ROOT_CHANNEL_ID (0).
     * @return array<string, mixed>|null Channel record, or null when not found.
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
     * @param string $slug Channel slug to resolve.
     * @return array<string, mixed>|null Channel record, or null when not found.
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
     * Returns one channel by either a numeric id or a slug string.
     *
     * A numeric string (e.g. '3') is treated as an id. A non-numeric string is
     * resolved as a slug.
     *
     * @param int|string $idOrSlug Channel id (int) or slug (string).
     * @return array<string, mixed>|null Channel record, or null when not found.
     */
    public function findByIdOrSlug(int|string $idOrSlug): ?array
    {
        if (is_int($idOrSlug)) {
            return $this->findById($idOrSlug);
        }

        $trimmed = trim($idOrSlug);
        if (ctype_digit($trimmed)) {
            return $this->findById((int) $trimmed);
        }

        return $this->findBySlug($trimmed);
    }

    /**
     * Loads the raw data array for a channel by its slug, or an empty array when not found.
     *
     * ChannelWrite uses this when it needs the current file-backed payload without
     * hydrating the full normalized repository row.
     *
     * @param string $slug Channel slug to look up.
     * @return array<string, mixed> Raw record data, or [] when the record does not exist.
     */
    public function loadRawBySlug(string $slug): array
    {
        $path = $this->findPathBySlug($slug);
        if ($path === null) {
            return [];
        }

        return $this->loadRawByPath($path);
    }

    /**
     * Returns channel assignment counts keyed by taxonomy set id.
     *
     * Iterates all channel records once and builds a map of set-id → channel count for the
     * given taxonomy kind. Used by panel list views to annotate each taxonomy set with how
     * many channels reference it, without a per-set round-trip.
     *
     * @param string $kind Taxonomy kind key: 'category' or 'tag'.
     * @return array<int, int> Map of set id to number of channels that explicitly select it.
     */
    public function explicitTaxonomySetCounts(string $kind): array
    {
        $field = strtolower(trim($kind)) === 'tag' ? 'tag_sets' : 'category_sets';
        $counts = [];

        foreach ($this->listRecords() as $record) {
            $selection = ChannelRepoParser::normalizeTaxonomySetSelection($record[$field] ?? [], false);
            foreach ($selection as $setId) {
                $counts[$setId] = (int) ($counts[$setId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Counts channels that explicitly include a given taxonomy set id in their configuration.
     *
     * Used before deleting a set to confirm no channels still reference it.
     *
     * @param string $kind  Taxonomy type: 'category' or 'tag'.
     * @param int    $setId Taxonomy set id to check for explicit assignments.
     * @return int Number of channels that list this set id in their category_sets or tag_sets field.
     */
    public function countExplicitTaxonomySetAssignments(string $kind, int $setId): int
    {
        if ($setId < 1) {
            return 0;
        }

        return (int) ($this->explicitTaxonomySetCounts($kind)[$setId] ?? 0);
    }

    /**
     * Returns a page-count map keyed by channel id.
     *
     * Public so ChannelWrite can reuse the page-count lookup during guarded deletes.
     *
     * @return array<int, int> Map of channel id to page count.
     */
    public function pageCountsByChannelId(): array
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
     * Returns all channel records indexed by id for O(1) lookup during page hydration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function channelsByIdMap(): array
    {
        return ChannelRepoParser::channelsByIdMap($this->listRecords());
    }

    /**
     * Returns the next available channel id, skipping any ids already in use.
     *
     * @param array<int, bool> $usedIds Mutable set of already-allocated ids (updated in place).
     * @param int              $maxId   Mutable high-water mark for id allocation (updated in place).
     * @return int Next allocatable channel id.
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

    /**
     * Normalizes a raw channel id value from a file record to a typed int, or null when invalid.
     *
     * @param mixed $value Raw value from the channel record file.
     * @return int|null Normalized channel id, or null when the value is not a valid id.
     */
    private function normalizeChannelId(mixed $value): ?int
    {
        return ChannelRepoParser::normalizeChannelId($value);
    }

    /**
     * Ensures the stock root channel exists with canonical immutable fields.
     *
     * Rewrites the file when id, slug, or reserved name drift from their canonical values.
     *
     * @return void
     */
    private function ensureRootChannelRecord(): void
    {
        $raw = $this->loadRawBySlug(ChannelRepoParser::ROOT_CHANNEL_SLUG);
        $createdAt = trim((string) ($raw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => ChannelRepoParser::ROOT_CHANNEL_ID,
            'name' => ChannelRepoParser::ROOT_CHANNEL_NAME,
            'slug' => ChannelRepoParser::ROOT_CHANNEL_SLUG,
            'description' => trim((string) ($raw['description'] ?? '')),
            'feed_enabled' => false,
            'editor_override' => ChannelRepoParser::normalizeEditorOverride(
                (string) ($raw['editor_override'] ?? 'inherit')
            ),
            'route_mode' => ChannelRepoParser::normalizeRouteMode((string) ($raw['route_mode'] ?? 'inherit')),
            'route_separator' => ChannelRepoParser::normalizeRouteSeparator(
                (string) ($raw['route_separator'] ?? 'inherit')
            ),
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_lg_path'] ?? null),
            'category_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($raw['category_sets'] ?? [], false),
            'tag_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($raw['tag_sets'] ?? [], false),
            'custom_fields' => is_array($raw['custom_fields'] ?? null) ? $raw['custom_fields'] : [],
            'overrides' => is_array($raw['overrides'] ?? null) ? $raw['overrides'] : [],
            'created_at' => $createdAt,
        ];

        if ($raw === [] || $this->rootRecordNeedsRewrite($raw)) {
            $this->channelFileScribe->writeRecordById(
                ChannelRepoParser::ROOT_CHANNEL_ID,
                ChannelRepoParser::ROOT_CHANNEL_SLUG,
                $record
            );
        }
    }

    /**
     * Returns whether the stored root-channel record needs to be rewritten.
     *
     * @param array<string, mixed> $raw Raw root-channel payload from disk.
     * @return bool True when immutable root fields differ from canonical values.
     */
    private function rootRecordNeedsRewrite(array $raw): bool
    {
        if (ChannelRepoParser::normalizeChannelId($raw['id'] ?? null) !== ChannelRepoParser::ROOT_CHANNEL_ID) {
            return true;
        }

        if (!ChannelRepoParser::isRootChannelSlug((string) ($raw['slug'] ?? ''))) {
            return true;
        }

        return trim((string) ($raw['name'] ?? '')) !== ChannelRepoParser::ROOT_CHANNEL_NAME;
    }

    /**
     * Returns a sorted list of all channel file paths in the store directory.
     *
     * @return array<int, string> Absolute file paths sorted by channel id ascending.
     */
    private function listChannelFilePaths(): array
    {
        $paths = $this->rawChannelFilePaths();
        usort($paths, static function (string $left, string $right): int {
            $leftId = self::filenameId($left);
            $rightId = self::filenameId($right);
            if ($leftId !== $rightId) {
                return $leftId <=> $rightId;
            }

            return strcmp($left, $right);
        });

        return $paths;
    }

    /**
     * Loads the raw PHP-array payload from a channel file at the given path.
     *
     * @param string $path Absolute path to the channel PHP file.
     * @return array<string, mixed> Deserialized record data, or [] on missing/invalid file.
     */
    private function loadRawByPath(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $this->invalidatePhpFileCache($path);

        try {
            /** @var mixed $raw */
            $raw = require $path;
        } catch (\Throwable) {
            return [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Loads and canonicalizes a channel record from a file path.
     *
     * @param string $path Absolute path to the channel PHP file.
     * @return array<string, mixed>|null Canonicalized channel record, or null if unrecognizable.
     */
    private function loadRecordFromPath(string $path): ?array
    {
        $raw = $this->loadRawByPath($path);
        if ($raw === []) {
            return null;
        }

        $channelId = $this->recordIdFromRaw($raw, $path);
        if ($channelId === null) {
            return null;
        }

        $slug = $this->recordSlugFromRaw($raw, $channelId, basename($path, '.php'));
        return $this->canonicalizeRecord($channelId, $slug, $raw);
    }

    /**
     * Returns all raw file paths in the channel directory without sorting or normalization.
     *
     * @return array<int, string> Unsorted absolute file paths.
     */
    private function rawChannelFilePaths(): array
    {
        $paths = glob($this->channelDirectory . '/*.php') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * Returns all file paths that could belong to the given channel id.
     *
     * Checks both the canonical filename pattern and the stored id field inside each file.
     *
     * @param int $id Channel id to search for.
     * @return array<int, string> Deduplicated list of matching absolute paths.
     */
    private function candidatePathsForId(int $id): array
    {
        $normalizedId = max(ChannelRepoParser::ROOT_CHANNEL_ID, $id);
        $paths = [];

        foreach (glob($this->channelDirectory . '/' . $normalizedId . '_*.php') ?: [] as $path) {
            $paths[] = $path;
        }

        foreach ($this->rawChannelFilePaths() as $path) {
            if (in_array($path, $paths, true)) {
                continue;
            }

            $raw = $this->loadRawByPath($path);
            if (($this->recordIdFromRaw($raw, $path) ?? -1) === $normalizedId) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Finds the first file path for a channel by id, or null if none exists.
     *
     * @param int $id Channel id.
     * @return string|null First matching path, or null.
     */
    private function findPathById(int $id): ?string
    {
        $paths = $this->candidatePathsForId($id);
        return $paths === [] ? null : $paths[0];
    }

    /**
     * Finds the file path for a channel by slug, or null if none exists.
     *
     * @param string $slug Channel slug.
     * @return string|null Matching path, or null.
     */
    private function findPathBySlug(string $slug): ?string
    {
        $normalizedSlug = strtolower(trim($slug));
        if (!ChannelRepoParser::isValidSlug($normalizedSlug)) {
            return null;
        }

        foreach ($this->rawChannelFilePaths() as $path) {
            $raw = $this->loadRawByPath($path);
            if ($raw === []) {
                continue;
            }

            $channelId = $this->recordIdFromRaw($raw, $path);
            if ($channelId === null) {
                continue;
            }

            if ($this->recordSlugFromRaw($raw, $channelId, basename($path, '.php')) === $normalizedSlug) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Resolves the channel id for a raw data array, falling back to the filename if the field is absent.
     *
     * @param array<string, mixed> $raw  Raw data loaded from the file.
     * @param string               $path Absolute path of the file (used for fallback id extraction).
     * @return int|null Resolved channel id, or null if it cannot be determined.
     */
    private function recordIdFromRaw(array $raw, string $path): ?int
    {
        $rawId = ChannelRepoParser::normalizeChannelId($raw['id'] ?? null);
        if ($rawId !== null) {
            return $rawId;
        }

        $filenameId = self::filenameId($path);
        if ($filenameId >= ChannelRepoParser::ROOT_CHANNEL_ID) {
            return $filenameId;
        }

        $fallbackSlug = $this->slugFromFilename($path);
        if ($fallbackSlug !== '' && ChannelRepoParser::isRootChannelSlug($fallbackSlug)) {
            return ChannelRepoParser::ROOT_CHANNEL_ID;
        }

        return null;
    }

    /**
     * Derives the canonical slug for a record, falling back through filename and name heuristics.
     *
     * @param array<string, mixed> $raw      Raw data loaded from the file.
     * @param int                  $id       Resolved channel id.
     * @param string               $fallback Basename (without .php) to try as a slug source.
     * @return string Canonical slug string.
     */
    private function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        if ($id === ChannelRepoParser::ROOT_CHANNEL_ID) {
            return ChannelRepoParser::ROOT_CHANNEL_SLUG;
        }

        $slug = strtolower(trim((string) ($raw['slug'] ?? '')));
        if (ChannelRepoParser::isValidSlug($slug)) {
            return $slug;
        }

        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = strtolower(trim((string) ($matches[1] ?? '')));
            if (ChannelRepoParser::isValidSlug($slug)) {
                return $slug;
            }
        }

        $slug = $this->slugFromFilename($fallback);
        if ($slug !== '' && ChannelRepoParser::isValidSlug($slug) && !preg_match('/^\d+$/', $slug)) {
            return $slug;
        }

        $nameSlug = strtolower(trim((string) ($raw['name'] ?? '')));
        $nameSlug = preg_replace('/[^a-z0-9]+/', '-', $nameSlug) ?? '';
        $nameSlug = trim($nameSlug, '-');
        $nameSlug = preg_replace('/-+/', '-', $nameSlug) ?? '';
        if ($nameSlug !== '' && ChannelRepoParser::isValidSlug($nameSlug)) {
            return $nameSlug;
        }

        return 'channel-' . $id;
    }

    /**
     * Builds a canonical channel record array with all fields validated and normalized.
     *
     * @param int                  $id   Resolved channel id.
     * @param string               $slug Resolved channel slug.
     * @param array<string, mixed> $raw  Source data to normalize.
     * @return array<string, mixed> Canonicalized channel record.
     */
    private function canonicalizeRecord(int $id, string $slug, array $raw): array
    {
        $normalizedId = max(ChannelRepoParser::ROOT_CHANNEL_ID, $id);
        $normalizedSlug = $this->recordSlugFromRaw($raw, $normalizedId, $slug);
        $name = trim((string) ($raw['name'] ?? ''));
        if ($normalizedId === ChannelRepoParser::ROOT_CHANNEL_ID) {
            $name = ChannelRepoParser::ROOT_CHANNEL_NAME;
            $normalizedSlug = ChannelRepoParser::ROOT_CHANNEL_SLUG;
        } elseif ($name === '') {
            $name = ucwords(str_replace('-', ' ', $normalizedSlug));
        }

        $createdAt = trim((string) ($raw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        return [
            'id' => $normalizedId,
            'name' => $name,
            'slug' => $normalizedSlug,
            'description' => trim((string) ($raw['description'] ?? '')),
            'feed_enabled' => ChannelRepoParser::normalizeFeedEnabled($raw['feed_enabled'] ?? false),
            'category_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($raw['category_sets'] ?? [], false),
            'tag_sets' => ChannelRepoParser::normalizeTaxonomySetSelection($raw['tag_sets'] ?? [], false),
            'editor_override' => ChannelRepoParser::normalizeEditorOverride(
                (string) ($raw['editor_override'] ?? 'inherit')
            ),
            'route_mode' => ChannelRepoParser::normalizeRouteMode((string) ($raw['route_mode'] ?? 'inherit')),
            'route_separator' => ChannelRepoParser::normalizeRouteSeparator(
                (string) ($raw['route_separator'] ?? 'inherit')
            ),
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_lg_path'] ?? null),
            'custom_fields' => is_array($raw['custom_fields'] ?? null) ? $raw['custom_fields'] : [],
            'overrides' => is_array($raw['overrides'] ?? null) ? $raw['overrides'] : [],
            'created_at' => $createdAt,
        ];
    }

    /**
     * Extracts the numeric id component from a channel filename, or -1 if the pattern does not match.
     *
     * @param string $path Absolute or relative path to a channel PHP file.
     * @return int Extracted id, or -1 on no match.
     */
    private static function filenameId(string $path): int
    {
        $basename = basename($path, '.php');
        if (preg_match('/^(\d+)(?:_[a-z0-9-]+)?$/', $basename, $matches) === 1) {
            return (int) ($matches[1] ?? -1);
        }

        return -1;
    }

    /**
     * Extracts the slug component from a channel filename, or the full basename when no id prefix is present.
     *
     * @param string $path Absolute or relative path to a channel PHP file.
     * @return string Lowercase slug string, or empty string on failure.
     */
    private function slugFromFilename(string $path): string
    {
        $basename = basename($path, '.php');
        if (preg_match('/^\d+_([a-z0-9-]+)$/', $basename, $matches) === 1) {
            return strtolower(trim((string) ($matches[1] ?? '')));
        }

        return strtolower(trim($basename));
    }

    /**
     * Clears the PHP stat cache and OPcache entry for a file path before a read.
     *
     * @param string $path Absolute path to invalidate.
     * @return void
     */
    private function invalidatePhpFileCache(string $path): void
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return;
        }

        clearstatcache(true, $normalized);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($normalized, true);
        }
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string Physical table name for the active backend.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
