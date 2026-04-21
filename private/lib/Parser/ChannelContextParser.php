<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ChannelContextParser.php
 * Channel record normalization policy, context hydration helpers, and filesystem read helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use RuntimeException;

/**
 * Combined normalization policy, context hydration, and filesystem reader for channel records.
 *
 * Static methods carry shared constants, normalization rules, and context-hydration helpers.
 * Instance methods handle read-side loading of the PHP-file-backed channel store on disk.
 * Writes and storage-layout repair live in `Raven\Lib\Scribe\ChannelScribe`.
 */
final class ChannelContextParser
{
    /** Id assigned to the implicit root (all-pages) channel. */
    public const ROOT_CHANNEL_ID = 0;

    /** Slug reserved for the root channel. */
    public const ROOT_CHANNEL_SLUG = 'root';

    /** Display name used internally for the root channel. */
    public const ROOT_CHANNEL_NAME = '<root>';

    /** Absolute path to the directory that holds channel PHP files. */
    private string $channelDirectory;

    /**
     * Prepares the reader for a given channel directory.
     *
     * @param string $channelDirectory Absolute path to the directory containing channel PHP files.
     */
    public function __construct(string $channelDirectory)
    {
        $this->channelDirectory = rtrim($channelDirectory, '/');
    }

    // -------------------------------------------------------------------------
    // Static normalization helpers (formerly ChannelRecordPolicy)
    // -------------------------------------------------------------------------

    /**
     * Returns whether a slug string meets channel slug format requirements.
     *
     * @param string $slug Slug to test.
     * @return bool        True when the slug is non-empty and contains only lowercase alphanumerics and hyphens.
     */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim($slug))) === 1;
    }

    /**
     * Returns whether the given id is the root channel id.
     *
     * @param int $id Channel id to test.
     * @return bool   True when $id equals ROOT_CHANNEL_ID.
     */
    public static function isRootChannelId(int $id): bool
    {
        return $id === self::ROOT_CHANNEL_ID;
    }

    /**
     * Returns whether the given slug is the root channel slug.
     *
     * @param string $slug Slug to test.
     * @return bool        True when the normalized slug equals ROOT_CHANNEL_SLUG.
     */
    public static function isRootChannelSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === self::ROOT_CHANNEL_SLUG;
    }

    /**
     * Normalizes an editor-override value to one of the accepted enum strings.
     *
     * @param string $value Raw override value.
     * @return string       One of 'inherit', 'tinymce', 'plaintext', 'autobr', or 'markdown'; defaults to 'inherit'.
     */
    public static function normalizeEditorOverride(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $normalized
            : 'inherit';
    }

    /**
     * Normalizes a raw route-mode value through the routing policy.
     *
     * @param string $value Raw route-mode string.
     * @return string       Validated route mode string.
     */
    public static function normalizeRouteMode(string $value): string
    {
        return ChannelRouteParser::normalizeChannelRouteMode($value);
    }

    /**
     * Normalizes a raw route-separator value through the routing policy.
     *
     * @param string $value Raw separator string.
     * @return string       Validated separator string.
     */
    public static function normalizeRouteSeparator(string $value): string
    {
        return ChannelRouteParser::normalizeChannelSeparator($value);
    }

    /**
     * Normalizes a nullable path scalar to a trimmed string or null.
     *
     * @param mixed $value Raw value; must be scalar or null.
     * @return string|null Trimmed path string, or null when the value is empty or non-scalar.
     */
    public static function normalizeNullablePath(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $path = trim((string) ($value ?? ''));
        return $path === '' ? null : $path;
    }

    /**
     * Normalizes a raw feed-enabled value to a boolean.
     *
     * @param mixed $value Raw value; accepts bool, int, float, or truthy string.
     * @return bool        True when the value represents an enabled state.
     */
    public static function normalizeFeedEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (!is_scalar($value) && $value !== null) {
            return false;
        }

        $normalized = strtolower(trim((string) ($value ?? '')));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Normalizes a raw channel id to an integer, or null when absent or invalid.
     *
     * @param mixed $value Raw value to parse; accepts any scalar or null.
     * @return int|null    Validated channel id (>= ROOT_CHANNEL_ID), or null.
     */
    public static function normalizeChannelId(mixed $value): ?int
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^-?\d+$/', $normalized) !== 1) {
            return null;
        }

        $id = (int) $normalized;
        return $id >= self::ROOT_CHANNEL_ID ? $id : null;
    }

    /**
     * Normalizes a taxonomy-set selection value for storage on a channel record.
     *
     * Delegates to SetParser::normalizeSelection for consistent cross-entity normalization.
     *
     * @param mixed $value      Raw selection value.
     * @param bool  $defaultAll When true and the selection is empty, returns the all-sets sentinel.
     * @return array<int, int|string> Sorted set-id array.
     */
    public static function normalizeTaxonomySetSelection(mixed $value, bool $defaultAll = true): array
    {
        return SetParser::normalizeSelection($value, $defaultAll);
    }

    // -------------------------------------------------------------------------
    // Static context-hydration helpers (formerly ChannelContextService)
    // -------------------------------------------------------------------------

    /**
     * Builds a map of channel id → channel record from a flat list of channel rows.
     *
     * @param array<int, array<string, mixed>> $channelRecords Flat list of channel record arrays.
     * @return array<int, array<string, mixed>>               Map keyed by integer channel id.
     */
    public static function channelsByIdMap(array $channelRecords): array
    {
        $map = [];
        foreach ($channelRecords as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $id = (int) ($channel['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $map[$id] = $channel;
        }

        return $map;
    }

    /**
     * Applies channel slug and name context fields to a row array.
     *
     * @param array<string, mixed>      $row     Row array to augment.
     * @param array<string, mixed>|null $channel Channel record, or null when the row has no channel.
     * @return array<string, mixed>              Row with channel_slug and channel_name fields populated.
     */
    public static function applyBasicChannelContext(array $row, ?array $channel): array
    {
        $row['channel_slug'] = $channel !== null ? (string) ($channel['slug'] ?? '') : '';
        $row['channel_name'] = $channel !== null ? (string) ($channel['name'] ?? '') : '';

        return $row;
    }

    /**
     * Applies channel routing context fields to a page row array.
     *
     * Includes basic channel context plus effective route mode and separator values.
     *
     * @param array<string, mixed>      $row     Page row array to augment.
     * @param array<string, mixed>|null $channel Channel record, or null when the page has no channel.
     * @return array<string, mixed>              Row with channel context and routing fields populated.
     */
    public static function applyPageChannelContext(array $row, ?array $channel): array
    {
        $row = self::applyBasicChannelContext($row, $channel);
        $row['route_mode_effective'] = $channel !== null
            ? (string) ($channel['route_mode'] ?? 'inherit')
            : 'inherit';
        $row['route_separator_effective'] = $channel !== null
            ? (string) ($channel['route_separator'] ?? 'inherit')
            : 'inherit';

        return $row;
    }

    /**
     * Resolves a channel id from a slug string using a caller-provided lookup callback.
     *
     * @param string|null $slug             Raw slug to resolve; empty/null returns null without error.
     * @param callable    $idBySlugResolver Callback accepting a normalized slug string and returning int|null.
     * @param string      $missingMessage   Exception message when the slug resolves to no known channel.
     * @return int|null                     Resolved channel id, or null when slug is empty.
     * @throws RuntimeException When the slug is non-empty but resolves to no channel.
     */
    public static function resolveChannelIdBySlug(
        ?string $slug,
        callable $idBySlugResolver,
        string $missingMessage = 'Selected channel does not exist.'
    ): ?int {
        $normalized = strtolower(trim((string) ($slug ?? '')));
        if ($normalized === '') {
            return null;
        }

        $resolved = $idBySlugResolver($normalized);
        $id = $resolved !== null ? (int) $resolved : null;
        if ($id === null || $id < 1) {
            throw new RuntimeException($missingMessage);
        }

        return $id;
    }

    // -------------------------------------------------------------------------
    // Instance filesystem reads
    // -------------------------------------------------------------------------

    /**
     * Returns a sorted list of all channel file paths in the store directory.
     *
     * @return array<int, string> Absolute file paths sorted by channel id ascending.
     */
    public function listChannelFilePaths(): array
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
     * Returns the canonical file path for a record given its id and slug.
     *
     * @param int    $id   Channel id.
     * @param string $slug Channel slug.
     * @return string      Absolute path where this channel's PHP file should live.
     */
    public function pathForRecord(int $id, string $slug): string
    {
        $safeId = max(self::ROOT_CHANNEL_ID, $id);
        $safeSlug = strtolower(trim($slug));
        if (!self::isValidSlug($safeSlug)) {
            $safeSlug = $safeId === self::ROOT_CHANNEL_ID
                ? self::ROOT_CHANNEL_SLUG
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
    public function loadRawBySlug(string $slug): array
    {
        $path = $this->findPathBySlug($slug);
        if ($path === null) {
            return [];
        }

        return $this->loadRawByPath($path);
    }

    /**
     * Loads the raw data array for a channel by its id, or an empty array if not found.
     *
     * @param int $id Channel id to look up.
     * @return array<string, mixed> Raw record data, or [] when the record does not exist.
     */
    public function loadRawById(int $id): array
    {
        $path = $this->findPathById($id);
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
    public function loadRawByPath(string $path): array
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
    public function loadRecordFromPath(string $path): ?array
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
        $normalizedId = max(self::ROOT_CHANNEL_ID, $id);
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
        if (!self::isValidSlug($normalizedSlug)) {
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
     * @return int|null                  Resolved channel id, or null if it cannot be determined.
     */
    private function recordIdFromRaw(array $raw, string $path): ?int
    {
        $rawId = self::normalizeChannelId($raw['id'] ?? null);
        if ($rawId !== null) {
            return $rawId;
        }

        $filenameId = self::filenameId($path);
        if ($filenameId >= self::ROOT_CHANNEL_ID) {
            return $filenameId;
        }

        // Allow a file whose basename is the root slug (no numeric prefix) to map to root.
        $fallbackSlug = $this->slugFromFilename($path);
        if ($fallbackSlug !== '' && self::isRootChannelSlug($fallbackSlug)) {
            return self::ROOT_CHANNEL_ID;
        }

        return null;
    }

    /**
     * Derives the canonical slug for a record, falling back through filename and name heuristics.
     *
     * @param array<string, mixed> $raw      Raw data loaded from the file.
     * @param int                  $id       Resolved channel id.
     * @param string               $fallback Basename (without .php) to try as a slug source.
     * @return string                        Canonical slug string.
     */
    private function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        if ($id === self::ROOT_CHANNEL_ID) {
            return self::ROOT_CHANNEL_SLUG;
        }

        $slug = strtolower(trim((string) ($raw['slug'] ?? '')));
        if (self::isValidSlug($slug)) {
            return $slug;
        }

        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = strtolower(trim((string) ($matches[1] ?? '')));
            if (self::isValidSlug($slug)) {
                return $slug;
            }
        }

        $slug = $this->slugFromFilename($fallback);
        if ($slug !== '' && self::isValidSlug($slug) && !preg_match('/^\d+$/', $slug)) {
            return $slug;
        }

        $nameSlug = strtolower(trim((string) ($raw['name'] ?? '')));
        $nameSlug = preg_replace('/[^a-z0-9]+/', '-', $nameSlug) ?? '';
        $nameSlug = trim($nameSlug, '-');
        $nameSlug = preg_replace('/-+/', '-', $nameSlug) ?? '';
        if ($nameSlug !== '' && self::isValidSlug($nameSlug)) {
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
     * @return array<string, mixed>      Canonicalized channel record.
     */
    private function canonicalizeRecord(int $id, string $slug, array $raw): array
    {
        $normalizedId = max(self::ROOT_CHANNEL_ID, $id);
        $normalizedSlug = $this->recordSlugFromRaw($raw, $normalizedId, $slug);
        $name = trim((string) ($raw['name'] ?? ''));
        if ($normalizedId === self::ROOT_CHANNEL_ID) {
            $name = self::ROOT_CHANNEL_NAME;
            $normalizedSlug = self::ROOT_CHANNEL_SLUG;
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
            'feed_enabled' => self::normalizeFeedEnabled($raw['feed_enabled'] ?? false),
            'category_sets' => self::normalizeTaxonomySetSelection($raw['category_sets'] ?? [], false),
            'tag_sets' => self::normalizeTaxonomySetSelection($raw['tag_sets'] ?? [], false),
            'editor_override' => self::normalizeEditorOverride(
                (string) ($raw['editor_override'] ?? 'inherit')
            ),
            'route_mode' => self::normalizeRouteMode((string) ($raw['route_mode'] ?? 'inherit')),
            'route_separator' => self::normalizeRouteSeparator(
                (string) ($raw['route_separator'] ?? 'inherit')
            ),
            'cover_image_path' => self::normalizeNullablePath($raw['cover_image_path'] ?? null),
            'cover_image_sm_path' => self::normalizeNullablePath($raw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => self::normalizeNullablePath($raw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => self::normalizeNullablePath($raw['cover_image_lg_path'] ?? null),
            'preview_image_path' => self::normalizeNullablePath($raw['preview_image_path'] ?? null),
            'preview_image_sm_path' => self::normalizeNullablePath($raw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => self::normalizeNullablePath($raw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => self::normalizeNullablePath($raw['preview_image_lg_path'] ?? null),
            'custom_fields' => is_array($raw['custom_fields'] ?? null) ? $raw['custom_fields'] : [],
            'overrides' => is_array($raw['overrides'] ?? null) ? $raw['overrides'] : [],
            'created_at' => $createdAt,
        ];
    }

    /**
     * Extracts the numeric id component from a channel filename, or -1 if the pattern does not match.
     *
     * @param string $path Absolute or relative path to a channel PHP file.
     * @return int         Extracted id, or -1 on no match.
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
     * @return string      Lowercase slug string, or empty string on failure.
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

}
