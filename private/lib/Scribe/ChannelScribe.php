<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/ChannelScribe.php
 * Channel filesystem persistence and canonicalization repair helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Lib\Parser\ChannelRepoParser;
use RuntimeException;

/**
 * Owns on-disk writes for the PHP-file-backed channel store.
 *
 * `Repository\ChannelRead` owns the file-backed channel-store reads while `ChannelRepoParser`
 * holds the stateless normalization primitives used here. This class owns directory
 * creation, atomic writes, stale-file cleanup, and filename/data repair for channel
 * metadata files.
 */
final class ChannelScribe
{
    /** Absolute path to the directory that holds channel PHP files. */
    private string $channelDirectory;

    /**
     * Prepares the scribe for one channel directory.
     *
     * @param string $channelDirectory Absolute path to the directory containing channel PHP files.
     */
    public function __construct(string $channelDirectory)
    {
        $this->channelDirectory = rtrim($channelDirectory, '/');
    }

    /**
     * Ensures all stored channel files use canonical filenames and canonical field values.
     *
     * Rewrites and renames any file whose stored id, slug, or field values differ from
     * what the current policy expects.
     *
     * @throws RuntimeException When the directory or one rewritten file cannot be persisted.
     * @return void
     */
    public function normalizeStorageLayout(): void
    {
        foreach ($this->rawChannelFilePaths() as $path) {
            $raw = $this->loadRawByPath($path);
            if ($raw === []) {
                continue;
            }

            $channelId = $this->recordIdFromRaw($raw, $path);
            if ($channelId === null) {
                continue;
            }

            $slug = $this->recordSlugFromRaw($raw, $channelId, basename($path, '.php'));
            $canonical = $this->canonicalizeRecord($channelId, $slug, $raw);
            $targetPath = $this->pathForRecord((int) $canonical['id'], (string) $canonical['slug']);
            if ($path === $targetPath && $canonical === $raw) {
                continue;
            }

            $this->writeRecordById((int) $canonical['id'], (string) $canonical['slug'], $canonical);
            if ($path !== $targetPath && is_file($path)) {
                @unlink($path);
                $this->invalidatePhpFileCache($path);
            }
        }
    }

    /**
     * Atomically writes one channel record to disk and removes stale duplicate paths for the same id.
     *
     * @param int $id Channel id to write.
     * @param string $slug Slug for the canonical filename.
     * @param array<string, mixed> $record Record data array to persist.
     * @throws RuntimeException When the file cannot be written or renamed into place.
     * @return void
     */
    public function writeRecordById(int $id, string $slug, array $record): void
    {
        $this->ensureDirectory();
        $canonical = $this->canonicalizeRecord($id, $slug, $record);
        $path = $this->pathForRecord((int) $canonical['id'], (string) $canonical['slug']);
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

        $this->invalidatePhpFileCache($tmpPath);
        $this->invalidatePhpFileCache($path);

        // Remove any stale files that matched the same id but had a different path.
        foreach ($this->candidatePathsForId((int) $canonical['id']) as $candidatePath) {
            if ($candidatePath === $path || !is_file($candidatePath)) {
                continue;
            }

            @unlink($candidatePath);
            $this->invalidatePhpFileCache($candidatePath);
        }
    }

    /**
     * Deletes all channel files on disk that belong to the given channel id.
     *
     * @param int $id Channel id whose files should be removed.
     * @throws RuntimeException When a matched file exists but cannot be deleted.
     * @return void
     */
    public function deleteById(int $id): void
    {
        foreach ($this->candidatePathsForId($id) as $path) {
            if (!is_file($path)) {
                continue;
            }

            if (!@unlink($path)) {
                throw new RuntimeException('Failed to delete channel file.');
            }
            $this->invalidatePhpFileCache($path);
        }
    }

    /**
     * Re-persists one channel file under the correct canonical path after an id change.
     *
     * @param string $slug Channel slug to locate the existing file.
     * @param int $id New channel id to assign.
     * @throws RuntimeException When the rewritten file cannot be persisted.
     * @return void
     */
    public function persistChannelId(string $slug, int $id): void
    {
        if ($id < ChannelRepoParser::ROOT_CHANNEL_ID || trim($slug) === '') {
            return;
        }

        $raw = $this->loadRawBySlug($slug);
        if ($raw === []) {
            return;
        }

        $this->writeRecordById($id, $slug, $raw);
    }

    /**
     * Creates the channel directory if it does not already exist.
     *
     * @throws RuntimeException When the directory cannot be created.
     * @return void
     */
    private function ensureDirectory(): void
    {
        if (is_dir($this->channelDirectory)) {
            return;
        }

        if (!@mkdir($this->channelDirectory, 0775, true) && !is_dir($this->channelDirectory)) {
            throw new RuntimeException('Failed to initialize channel directory.');
        }
    }

    /**
     * Returns the canonical file path for a record given its id and slug.
     *
     * @param int $id Channel id.
     * @param string $slug Channel slug.
     * @return string Absolute path where this channel's PHP file should live.
     */
    private function pathForRecord(int $id, string $slug): string
    {
        $safeId = max(ChannelRepoParser::ROOT_CHANNEL_ID, $id);
        $safeSlug = strtolower(trim($slug));
        if (!ChannelRepoParser::isValidSlug($safeSlug)) {
            $safeSlug = $safeId === ChannelRepoParser::ROOT_CHANNEL_ID
                ? ChannelRepoParser::ROOT_CHANNEL_SLUG
                : ('channel-' . $safeId);
        }

        return $this->channelDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
    }

    /**
     * Loads the raw data array for a channel by its slug, or an empty array if not found.
     *
     * @param string $slug Channel slug to look up.
     * @return array<string, mixed> Raw record data, or [] when the record does not exist.
     */
    private function loadRawBySlug(string $slug): array
    {
        $path = $this->findPathBySlug($slug);
        if ($path === null) {
            return [];
        }

        return $this->loadRawByPath($path);
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
     * @param array<string, mixed> $raw Raw data loaded from the file.
     * @param string $path Absolute path of the file.
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

        // Allow a file whose basename is the root slug (no numeric prefix) to map to root.
        $fallbackSlug = $this->slugFromFilename($path);
        if ($fallbackSlug !== '' && ChannelRepoParser::isRootChannelSlug($fallbackSlug)) {
            return ChannelRepoParser::ROOT_CHANNEL_ID;
        }

        return null;
    }

    /**
     * Derives the canonical slug for a record, falling back through filename and name heuristics.
     *
     * @param array<string, mixed> $raw Raw data loaded from the file.
     * @param int $id Resolved channel id.
     * @param string $fallback Basename (without `.php`) to try as a slug source.
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
     * @param int $id Resolved channel id.
     * @param string $slug Resolved channel slug.
     * @param array<string, mixed> $raw Source data to normalize.
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
     * Clears the PHP stat cache and OPcache entry for a file path.
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
}
