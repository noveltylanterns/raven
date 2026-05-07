<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelWrite.php
 * Write-side data access for filesystem-backed channel metadata (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Parser\ChannelRepoParser;
use Raven\Core\Router\ChannelPolicy;
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
     *   feed_enabled?: bool,
     *   category_sets?: array<int, int|string>,
     *   tag_sets?: array<int, int|string>,
     *   editor_override?: string,
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
        $editorOverride = ChannelRepoParser::normalizeEditorOverride((string) ($data['editor_override'] ?? 'inherit'));
        $routeMode = ChannelPolicy::normalizeChannelRouteMode((string) ($data['route_mode'] ?? 'inherit'));
        $routeSeparator = ChannelPolicy::normalizeChannelSeparator((string) ($data['route_separator'] ?? 'inherit'));

        if ($name === '' || !ChannelRepoParser::isValidSlug($slug)) {
            throw new RuntimeException('Channel name and slug are required.');
        }

        if (ChannelRepoParser::isRootChannelSlug($slug) || ($idProvided && ChannelRepoParser::isRootChannelId($id))) {
            throw new RuntimeException('The stock <root> channel is reserved and cannot be edited.');
        }

        $existingBySlug = $this->read->findBySlug($slug);
        if (is_array($existingBySlug) && (int) ($existingBySlug['id'] ?? 0) !== $id) {
            throw new RuntimeException('A channel with that slug already exists.');
        }

        $existingRecord = $idProvided ? $this->read->findById($id) : null;
        $oldSlug = is_array($existingRecord) ? (string) ($existingRecord['slug'] ?? '') : '';
        $channelId = is_array($existingRecord)
            ? (int) ($existingRecord['id'] ?? 0)
            : $this->nextChannelId();

        $currentRaw = $oldSlug !== '' ? $this->read->loadRawBySlug($oldSlug) : [];
        $customFields = is_array($currentRaw['custom_fields'] ?? null) ? $currentRaw['custom_fields'] : [];
        $overrides = is_array($currentRaw['overrides'] ?? null) ? $currentRaw['overrides'] : [];
        $feedEnabled = array_key_exists('feed_enabled', $data)
            ? ChannelRepoParser::normalizeFeedEnabled($data['feed_enabled'])
            : ChannelRepoParser::normalizeFeedEnabled($currentRaw['feed_enabled'] ?? false);
        $categorySets = array_key_exists('category_sets', $data)
            ? SetParser::normalizeSelection($data['category_sets'], false)
            : SetParser::normalizeSelection($currentRaw['category_sets'] ?? [], false);
        $tagSets = array_key_exists('tag_sets', $data)
            ? SetParser::normalizeSelection($data['tag_sets'], false)
            : SetParser::normalizeSelection($currentRaw['tag_sets'] ?? [], false);
        $createdAt = trim((string) ($currentRaw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => $channelId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'feed_enabled' => $feedEnabled,
            'category_sets' => $categorySets,
            'tag_sets' => $tagSets,
            'editor_override' => $editorOverride,
            'route_mode' => $routeMode,
            'route_separator' => $routeSeparator,
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_lg_path'] ?? null),
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
        if (!is_array($record)) {
            throw new RuntimeException('Channel not found.');
        }

        $slug = (string) ($record['slug'] ?? '');
        if ($slug === '') {
            throw new RuntimeException('Channel slug is invalid.');
        }

        $currentRaw = $this->read->loadRawBySlug($slug);
        $raw = [
            'id' => (int) ($record['id'] ?? $id),
            'name' => (string) ($record['name'] ?? ''),
            'slug' => $slug,
            'description' => (string) ($record['description'] ?? ''),
            'feed_enabled' => ChannelRepoParser::normalizeFeedEnabled(
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
            'editor_override' => (string) ($record['editor_override'] ?? 'inherit'),
            'route_mode' => (string) ($record['route_mode'] ?? 'inherit'),
            'route_separator' => (string) ($record['route_separator'] ?? 'inherit'),
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_lg_path'] ?? null),
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
        if (ChannelRepoParser::isRootChannelId($id)) {
            throw new RuntimeException('The stock <root> channel cannot be deleted.');
        }

        $record = $this->read->findById($id);
        if (!is_array($record)) {
            return;
        }

        $pageCounts = $this->read->pageCountsByChannelId();
        if ((int) ($pageCounts[$id] ?? 0) > 0) {
            throw new RuntimeException('Cannot delete a channel that has pages assigned to it.');
        }

        $pages = $this->table('pages');
        $redirects = $this->table('redirects');

        $this->db->beginTransaction();
        try {
            // Reassign any stray rows to root before the channel file disappears.
            $detachPages = $this->db->prepare(
                'UPDATE ' . $pages . ' SET channel = :root_channel WHERE channel = :channel_id'
            );
            $detachPages->execute([
                ':root_channel' => ChannelRepoParser::ROOT_CHANNEL_ID,
                ':channel_id' => $id,
            ]);

            $detachRedirects = $this->db->prepare(
                'UPDATE ' . $redirects . ' SET channel = :root_channel WHERE channel = :channel_id'
            );
            $detachRedirects->execute([
                ':root_channel' => ChannelRepoParser::ROOT_CHANNEL_ID,
                ':channel_id' => $id,
            ]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        self::deleteRecordById($this->channelDirectory, $id);
        $this->read->clearCache();
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

        foreach (ChannelRead::rawChannelFilePathsInDirectory($normalizedDirectory) as $path) {
            $raw = ChannelRead::loadRawByPathStatic($path);
            if ($raw === []) {
                continue;
            }

            $channelId = ChannelRead::recordIdFromRawStatic($raw, $path);
            if ($channelId === null) {
                continue;
            }

            $slug = ChannelRead::recordSlugFromRawStatic($raw, $channelId, basename($path, '.php'));
            $canonical = ChannelRead::canonicalizeRecordStatic($channelId, $slug, $raw);
            $targetPath = self::pathForRecord($normalizedDirectory, (int) $canonical['id'], (string) $canonical['slug']);
            if ($path === $targetPath && $canonical === $raw) {
                continue;
            }

            self::writeRecordById($normalizedDirectory, (int) $canonical['id'], (string) $canonical['slug'], $canonical);
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
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write channel file.');
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize channel file.');
        }

        ChannelRead::invalidatePhpFileCacheStatic($tmpPath);
        ChannelRead::invalidatePhpFileCacheStatic($path);

        // Remove any stale files that matched the same id but had a different path.
        foreach (ChannelRead::candidatePathsForIdInDirectory($normalizedDirectory, (int) $canonical['id']) as $candidatePath) {
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
        foreach (ChannelRead::candidatePathsForIdInDirectory($normalizedDirectory, $id) as $path) {
            if (!is_file($path)) {
                continue;
            }

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
        if ($id < ChannelRepoParser::ROOT_CHANNEL_ID || trim($slug) === '') {
            return;
        }

        $path = ChannelRead::findPathBySlugInDirectory($normalizedDirectory, $slug);
        if ($path === null) {
            return;
        }

        $raw = ChannelRead::loadRawByPathStatic($path);
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
        foreach ($this->read->listRecords() as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId > $maxId) {
                $maxId = $recordId;
            }
        }

        return $maxId + 1;
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
        if (is_dir($channelDirectory)) {
            return;
        }

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
        $safeId = max(ChannelRepoParser::ROOT_CHANNEL_ID, $id);
        $safeSlug = strtolower(trim($slug));
        if (!ChannelRepoParser::isValidSlug($safeSlug)) {
            $safeSlug = $safeId === ChannelRepoParser::ROOT_CHANNEL_ID
                ? ChannelRepoParser::ROOT_CHANNEL_SLUG
                : ('channel-' . $safeId);
        }

        return $channelDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
    }
}
