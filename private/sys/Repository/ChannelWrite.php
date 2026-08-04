<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelWrite.php
 * Write-side data access for filesystem-backed channel metadata (INSERT, UPDATE, DELETE).
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Core\Repository\ChannelShared;
use Raven\Core\Router\ChannelPolicy;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Parser\SetParser;
use RuntimeException;

/**
 * INSERT, UPDATE, and DELETE methods for channel records.
 *
 * Read operations (SELECT, lookup) live in ChannelRead, which is injected here
 * so that write-side validation can perform existence lookups without duplicating queries.
 * After each mutation, calls $read->clearCache() so subsequent reads reflect disk state.
 */
final class ChannelWrite
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $read;
    private string $channelDirectory;

    /**
     * @param PDO         $db               Active database connection.
     * @param string      $driver           Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix           Table name prefix for this Raven installation.
     * @param ChannelRead $read             Read-side instance for existence lookups and cache invalidation.
     * @param string|null $channelDirectory Absolute path to the channel file directory; defaults to private/dat/channel.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, ChannelRead $read, ?string $channelDirectory = null)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->read = $read;
        $this->channelDirectory = rtrim($channelDirectory ?? (dirname(__DIR__, 3) . '/dat/channel'), '/');
    }

    /**
     * Creates or updates one channel and returns the channel id.
     *
     * @param array{
     *   id: int|null,
     *   name: string,
     *   slug: string,
     *   description: string,
     *   parent_id?: int,
     *   index?: string,
     *   feed_enabled?: bool,
     *   category_sets?: array<int, int|string>,
     *   tag_sets?: array<int, int|string>,
     *   editor_override?: string,
     *   theme_override?: string,
     *   route_mode?: string,
     *   route_separator?: string
     * } $data Normalized channel fields.
     * @return int The saved channel id.
     */
    public function save(array $data): int
    {
        $idProvided = array_key_exists('id', $data) && $data['id'] !== null;
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $name = trim((string) ($data['name'] ?? ''));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $editorOverride = ChannelShared::normalizeEditorOverride((string) ($data['editor_override'] ?? 'inherit'));
        $themeOverride = ChannelShared::normalizeThemeOverride((string) ($data['theme_override'] ?? 'inherit'));
        $routeMode = ChannelPolicy::normalizeChannelRouteMode((string) ($data['route_mode'] ?? 'inherit'));
        $routeSeparator = ChannelPolicy::normalizeChannelSeparator((string) ($data['route_separator'] ?? 'inherit'));

        // Name and slug are the minimum required identity fields for channel persistence.
        if ($name === '' || !ChannelShared::isValidSlug($slug)) {
            throw new RuntimeException('Channel name and slug are required.');
        }

        // Root channel identity is reserved and cannot be modified through generic save flow.
        if (ChannelShared::isRootChannelSlug($slug) || ($idProvided && ChannelShared::isRootChannelId($id))) {
            throw new RuntimeException('The stock <root> channel is reserved and cannot be edited.');
        }

        $existingBySlug = $this->read->findBySlug($slug);
        // Enforce slug uniqueness across all non-current channel records.
        if (is_array($existingBySlug) && (int) ($existingBySlug['id'] ?? 0) !== $id) {
            throw new RuntimeException('A channel with that slug already exists.');
        }

        $existingRecord = $idProvided ? $this->read->findById($id) : null;
        $oldSlug = is_array($existingRecord) ? (string) ($existingRecord['slug'] ?? '') : '';
        $channelId = is_array($existingRecord)
            ? (int) ($existingRecord['id'] ?? 0)
            : $this->nextChannelId();
        $parentId = ChannelShared::normalizeParentId($data['parent_id'] ?? ChannelShared::ROOT_CHANNEL_ID);
        // Validate against the current hierarchy so forged saves cannot create cycles or orphaned parents.
        if (!$this->isAvailableParentId($channelId, $parentId)) {
            throw new RuntimeException('The selected parent channel is not available.');
        }

        $currentRaw = $oldSlug !== '' ? $this->read->loadRawBySlug($oldSlug) : [];
        $customFields = is_array($currentRaw['custom_fields'] ?? null) ? $currentRaw['custom_fields'] : [];
        $overrides = is_array($currentRaw['overrides'] ?? null) ? $currentRaw['overrides'] : [];
        $feedEnabled = array_key_exists('feed_enabled', $data)
            ? ChannelShared::normalizeFeedEnabled($data['feed_enabled'])
            : ChannelShared::normalizeFeedEnabled($currentRaw['feed_enabled'] ?? false);
        $categorySets = array_key_exists('category_sets', $data)
            ? SetParser::normalizeSelection($data['category_sets'], false)
            : SetParser::normalizeSelection($currentRaw['category_sets'] ?? [], false);
        $tagSets = array_key_exists('tag_sets', $data)
            ? SetParser::normalizeSelection($data['tag_sets'], false)
            : SetParser::normalizeSelection($currentRaw['tag_sets'] ?? [], false);
        $indexRouteMode = array_key_exists('index', $data)
            ? ChannelPolicy::normalizeChannelIndexRouteMode((string) $data['index'])
            : ChannelPolicy::normalizeChannelIndexRouteMode((string) ($currentRaw['index'] ?? 'auto'));
        $createdAt = trim((string) ($currentRaw['created_at'] ?? ''));
        // Preserve original created_at when available; backfill for legacy rows otherwise.
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => $channelId,
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parentId,
            'index' => $indexRouteMode,
            'description' => $description,
            'feed_enabled' => $feedEnabled,
            'category_sets' => $categorySets,
            'tag_sets' => $tagSets,
            'editor_override' => $editorOverride,
            'theme_override' => $themeOverride,
            'route_mode' => $routeMode,
            'route_separator' => $routeSeparator,
            'cover_image_path' => ChannelShared::normalizeNullablePath($currentRaw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelShared::normalizeNullablePath($currentRaw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelShared::normalizeNullablePath($currentRaw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelShared::normalizeNullablePath($currentRaw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelShared::normalizeNullablePath($currentRaw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelShared::normalizeNullablePath($currentRaw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelShared::normalizeNullablePath($currentRaw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelShared::normalizeNullablePath($currentRaw['preview_image_lg_path'] ?? null),
            'custom_fields' => $customFields,
            'overrides' => $overrides,
            'created_at' => $createdAt,
        ];

        self::writeRecordById($this->channelDirectory, $channelId, $slug, $record);
        $this->read->clearCache();
        return $channelId;
    }

    /**
     * Updates one channel's cover/preview image path set.
     *
     * @param int   $id    Channel id to update.
     * @param array{
     *   cover_image_path: string|null,
     *   cover_image_sm_path: string|null,
     *   cover_image_md_path: string|null,
     *   cover_image_lg_path: string|null,
     *   preview_image_path: string|null,
     *   preview_image_sm_path: string|null,
     *   preview_image_md_path: string|null,
     *   preview_image_lg_path: string|null
     * } $paths Image path strings keyed by size variant.
     * @return void
     */
    public function updateImagePaths(int $id, array $paths): void
    {
        $record = $this->read->findById($id);
        // Updating image paths requires an existing channel record.
        if (!is_array($record)) {
            throw new RuntimeException('Channel not found.');
        }

        $slug = (string) ($record['slug'] ?? '');
        // Image writes require a valid slug so the target channel file can be resolved.
        if ($slug === '') {
            throw new RuntimeException('Channel slug is invalid.');
        }

        $currentRaw = $this->read->loadRawBySlug($slug);
        $raw = [
            'id' => (int) ($record['id'] ?? $id),
            'name' => (string) ($record['name'] ?? ''),
            'slug' => $slug,
            'parent_id' => ChannelShared::normalizeParentId(
                $currentRaw['parent_id'] ?? ($record['parent_id'] ?? ChannelShared::ROOT_CHANNEL_ID)
            ),
            'description' => (string) ($record['description'] ?? ''),
            'feed_enabled' => ChannelShared::normalizeFeedEnabled(
                $currentRaw['feed_enabled'] ?? ($record['feed_enabled'] ?? false)
            ),
            'category_sets' => SetParser::normalizeSelection(
                $currentRaw['category_sets'] ?? ($record['category_sets'] ?? []),
                false
            ),
            'tag_sets' => SetParser::normalizeSelection(
                $currentRaw['tag_sets'] ?? ($record['tag_sets'] ?? []),
                false
            ),
            'index' => ChannelPolicy::normalizeChannelIndexRouteMode(
                (string) ($currentRaw['index'] ?? ($record['index'] ?? 'auto'))
            ),
            'editor_override' => (string) ($record['editor_override'] ?? 'inherit'),
            'theme_override' => ChannelShared::normalizeThemeOverride(
                (string) ($currentRaw['theme_override'] ?? ($record['theme_override'] ?? 'inherit'))
            ),
            'route_mode' => (string) ($record['route_mode'] ?? 'inherit'),
            'route_separator' => (string) ($record['route_separator'] ?? 'inherit'),
            'cover_image_path' => ChannelShared::normalizeNullablePath($paths['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelShared::normalizeNullablePath($paths['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelShared::normalizeNullablePath($paths['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelShared::normalizeNullablePath($paths['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelShared::normalizeNullablePath($paths['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelShared::normalizeNullablePath($paths['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelShared::normalizeNullablePath($paths['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelShared::normalizeNullablePath($paths['preview_image_lg_path'] ?? null),
            'custom_fields' => is_array($currentRaw['custom_fields'] ?? null) ? $currentRaw['custom_fields'] : [],
            'overrides' => is_array($currentRaw['overrides'] ?? null) ? $currentRaw['overrides'] : [],
            'created_at' => trim((string) ($currentRaw['created_at'] ?? '')) !== ''
                ? (string) $currentRaw['created_at']
                : gmdate('Y-m-d H:i:s'),
        ];

        self::writeRecordById($this->channelDirectory, (int) ($record['id'] ?? $id), $slug, $raw);
        $this->read->clearCache();
    }

    /**
     * Deletes one channel. Throws if the channel still has pages or redirects assigned.
     *
     * @param int $id Channel id to delete.
     * @throws \RuntimeException When the channel has associated pages or cannot be removed.
     * @return void
     */
    public function deleteById(int $id): void
    {
        // Root channel is a required system record and cannot be deleted.
        if (ChannelShared::isRootChannelId($id)) {
            throw new RuntimeException('The stock <root> channel cannot be deleted.');
        }

        $record = $this->read->findById($id);
        // Nothing to delete when channel no longer exists.
        if (!is_array($record)) {
            return;
        }

        $pageCounts = $this->read->pageCountsByChannelId();
        // Refuse deletion while pages are still assigned to this channel.
        if ((int) ($pageCounts[$id] ?? 0) > 0) {
            throw new RuntimeException('Cannot delete a channel that has pages assigned to it.');
        }

        $pages = $this->table('pages');
        $redirects = $this->table('redirects');

        $this->db->beginTransaction();
        // Keep detach operations atomic so partial reassignment cannot occur on failure.
        try {
            // Reassign any stray rows to root before the channel file disappears.
            $detachPages = $this->db->prepare(
                'UPDATE ' . $pages . ' SET channel = :root_channel WHERE channel = :channel_id'
            );
            $detachPages->execute([
                ':root_channel' => ChannelShared::ROOT_CHANNEL_ID,
                ':channel_id' => $id,
            ]);

            $detachRedirects = $this->db->prepare(
                'UPDATE ' . $redirects . ' SET channel = :root_channel WHERE channel = :channel_id'
            );
            $detachRedirects->execute([
                ':root_channel' => ChannelShared::ROOT_CHANNEL_ID,
                ':channel_id' => $id,
            ]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            // Roll back only when transaction remains active after failure.
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        // Move children to root before deleting their parent so no stored channel becomes orphaned.
        $this->reparentChildrenToRoot($id);
        self::deleteRecordById($this->channelDirectory, $id);
        $this->read->clearCache();
    }

    /**
     * Moves direct children of a deleted channel to the stock root channel.
     *
     * @param int $deletedId Channel id whose children need a safe replacement parent.
     * @throws RuntimeException When a child channel cannot be rewritten.
     * @return void
     */
    private function reparentChildrenToRoot(int $deletedId): void
    {
        foreach ($this->read->listRecords() as $child) {
            $childId = (int) ($child['id'] ?? ChannelShared::ROOT_CHANNEL_ID);
            if ($childId === ChannelShared::ROOT_CHANNEL_ID
                || ChannelShared::normalizeParentId($child['parent_id'] ?? 0) !== $deletedId) {
                continue;
            }

            $child['parent_id'] = ChannelShared::ROOT_CHANNEL_ID;
            self::writeRecordById(
                $this->channelDirectory,
                $childId,
                (string) ($child['slug'] ?? ''),
                $child
            );
        }
    }

    /**
     * Ensures all stored channel files use canonical filenames and canonical field values.
     *
     * @param string $channelDirectory Absolute path to the channel file directory.
     * @throws RuntimeException When the directory or one rewritten file cannot be persisted.
     * @return void
     */
    public static function normalizeStorageLayout(string $channelDirectory): void
    {
        $normalizedDirectory = rtrim($channelDirectory, '/');

        // Revisit every channel file and rewrite anything that is non-canonical.
        foreach (ChannelRead::rawChannelFilePathsInDirectory($normalizedDirectory) as $path) {
            $raw = ChannelRead::loadRawByPathStatic($path);
            // Skip unreadable or empty payload files.
            if ($raw === []) {
                continue;
            }

            $channelId = ChannelRead::recordIdFromRawStatic($raw, $path);
            // Skip files whose id cannot be resolved.
            if ($channelId === null) {
                continue;
            }

            $slug = ChannelRead::recordSlugFromRawStatic($raw, $channelId, basename($path, '.php'));
            $canonical = ChannelRead::canonicalizeRecordStatic($channelId, $slug, $raw);
            $targetPath = self::pathForRecord($normalizedDirectory, (int) $canonical['id'], (string) $canonical['slug']);
            // Skip rewrite when file path and payload already match canonical output.
            if ($path === $targetPath && $canonical === $raw) {
                continue;
            }

            self::writeRecordById($normalizedDirectory, (int) $canonical['id'], (string) $canonical['slug'], $canonical);
            // Remove obsolete pre-canonical file when rewrite moved the record to a new path.
            if ($path !== $targetPath && is_file($path)) {
                @unlink($path);
                ChannelRead::invalidatePhpFileCacheStatic($path);
            }
        }
    }

    /**
     * Atomically writes one channel record to disk.
     *
     * @param string $channelDirectory Absolute path to the channel file directory.
     * @param int $id Channel id to write.
     * @param string $slug Slug for the canonical filename.
     * @param array<string, mixed> $record Record data array to persist.
     * @throws RuntimeException When the file cannot be written or renamed into place.
     * @return void
     */
    public static function writeRecordById(string $channelDirectory, int $id, string $slug, array $record): void
    {
        $normalizedDirectory = rtrim($channelDirectory, '/');
        self::ensureDirectory($normalizedDirectory);
        $canonical = ChannelRead::canonicalizeRecordStatic($id, $slug, $record);
        $path = self::pathForRecord($normalizedDirectory, (int) $canonical['id'], (string) $canonical['slug']);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($canonical, true) . ";\n";

        // Write to a temp file first so the final rename is atomic.
        $tmpPath = $path . '.tmp';
        // Failing temp write means atomic replacement cannot proceed safely.
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write channel file.');
        }

        // Promote temp file atomically; rollback temp artifact on failure.
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize channel file.');
        }

        ChannelRead::invalidatePhpFileCacheStatic($tmpPath);
        ChannelRead::invalidatePhpFileCacheStatic($path);

        // Remove any stale files that matched the same id but had a different path.
        foreach (ChannelRead::candidatePathsForIdInDirectory($normalizedDirectory, (int) $canonical['id']) as $candidatePath) {
            // Keep current canonical file and ignore paths already removed.
            if ($candidatePath === $path || !is_file($candidatePath)) {
                continue;
            }

            @unlink($candidatePath);
            ChannelRead::invalidatePhpFileCacheStatic($candidatePath);
        }
    }

    /**
     * Deletes all channel files on disk that belong to the given channel id.
     *
     * @param string $channelDirectory Absolute path to the channel file directory.
     * @param int $id Channel id whose files should be removed.
     * @throws RuntimeException When a matched file exists but cannot be deleted.
     * @return void
     */
    public static function deleteRecordById(string $channelDirectory, int $id): void
    {
        $normalizedDirectory = rtrim($channelDirectory, '/');
        // Attempt deletion for every file candidate mapped to this channel id.
        foreach (ChannelRead::candidatePathsForIdInDirectory($normalizedDirectory, $id) as $path) {
            // Skip candidates already removed or never present on disk.
            if (!is_file($path)) {
                continue;
            }

            // Surface deletion failure so callers can react to filesystem permission issues.
            if (!@unlink($path)) {
                throw new RuntimeException('Failed to delete channel file.');
            }
            ChannelRead::invalidatePhpFileCacheStatic($path);
        }
    }

    /**
     * Re-persists one channel file under the correct canonical path after an id change.
     *
     * @param string $channelDirectory Absolute path to the channel file directory.
     * @param string $slug Channel slug to locate the existing file.
     * @param int $id New channel id to assign.
     * @throws RuntimeException When the rewritten file cannot be persisted.
     * @return void
     */
    public static function persistChannelId(string $channelDirectory, string $slug, int $id): void
    {
        $normalizedDirectory = rtrim($channelDirectory, '/');
        // Ignore invalid id/slug combinations that cannot map to canonical channel records.
        if ($id < ChannelShared::ROOT_CHANNEL_ID || trim($slug) === '') {
            return;
        }

        $path = ChannelRead::findPathBySlugInDirectory($normalizedDirectory, $slug);
        // Nothing to persist when no file currently matches the slug.
        if ($path === null) {
            return;
        }

        $raw = ChannelRead::loadRawByPathStatic($path);
        // Skip rewrites when source file payload cannot be loaded.
        if ($raw === []) {
            return;
        }

        self::writeRecordById($normalizedDirectory, $id, $slug, $raw);
    }

    /**
     * Returns the next channel id as max(existing ids) + 1.
     *
     * @return int Next sequential channel id.
     */
    private function nextChannelId(): int
    {
        $maxId = 0;
        // Scan all known channels and keep the highest assigned id.
        foreach ($this->read->listRecords() as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            // Update high-water mark only when current record id is larger.
            if ($recordId > $maxId) {
                $maxId = $recordId;
            }
        }

        return $maxId + 1;
    }

    /**
     * Returns whether a parent id is present in the current cycle-safe parent options.
     *
     * @param int $channelId Channel being created or edited.
     * @param int $parentId Candidate parent channel id.
     * @return bool True when the candidate is root or an eligible existing channel.
     */
    private function isAvailableParentId(int $channelId, int $parentId): bool
    {
        foreach ($this->read->listParentOptions($channelId) as $option) {
            if ((int) ($option['id'] ?? -1) === $parentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string Physical table name for the active backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Creates the channel directory if it does not already exist.
     *
     * @param string $channelDirectory Absolute channel directory path.
     * @throws RuntimeException When the directory cannot be created.
     * @return void
     */
    private static function ensureDirectory(string $channelDirectory): void
    {
        // Existing directory satisfies storage precondition for record writes.
        if (is_dir($channelDirectory)) {
            return;
        }

        // Create directory recursively; second is_dir guard covers race conditions.
        if (!@mkdir($channelDirectory, 0775, true) && !is_dir($channelDirectory)) {
            throw new RuntimeException('Failed to initialize channel directory.');
        }
    }

    /**
     * Returns the canonical file path for a record given its id and slug.
     *
     * @param string $channelDirectory Absolute channel directory path.
     * @param int $id Channel id.
     * @param string $slug Channel slug.
     * @return string Absolute path where this channel's PHP file should live.
     */
    private static function pathForRecord(string $channelDirectory, int $id, string $slug): string
    {
        $safeId = max(ChannelShared::ROOT_CHANNEL_ID, $id);
        $safeSlug = strtolower(trim($slug));
        // Substitute deterministic fallback slug when provided slug is invalid.
        if (!ChannelShared::isValidSlug($safeSlug)) {
            $safeSlug = $safeId === ChannelShared::ROOT_CHANNEL_ID
                ? ChannelShared::ROOT_CHANNEL_SLUG
                : ('channel-' . $safeId);
        }

        return $channelDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
    }
}
