<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/SetContext.php
 * Taxonomy set record normalization policy and filesystem persistence helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use RuntimeException;

/**
 * Combined normalization policy and filesystem store for taxonomy set records.
 *
 * Static methods carry shared constants and data-normalization rules; instance
 * methods handle reading and writing the PHP-file-backed set store on disk.
 * Consolidates the former TaxonomySetRecordPolicy and TaxonomySetFileStoreService.
 */
final class SetContext
{
    /** Sentinel set-id meaning "all sets"; never persisted as a real record. */
    public const ALL_SET_ID = 0;

    /** Id assigned to the system-default set that is always present. */
    public const DEFAULT_SET_ID = 1;

    /** Slug reserved for the system-default set. */
    public const DEFAULT_SET_SLUG = 'default';

    /** Absolute path to the directory that holds set PHP files. */
    private string $setDirectory;

    /** Taxonomy type string (e.g. 'category' or 'tag') used for default-set labels. */
    private string $taxonomyType;

    /**
     * Prepares the store for a given set directory and taxonomy type.
     *
     * @param string $setDirectory Absolute path to the directory containing set PHP files.
     * @param string $taxonomyType Taxonomy type label (e.g. 'category', 'tag'); used when generating default-set names.
     */
    public function __construct(string $setDirectory, string $taxonomyType)
    {
        $this->setDirectory = rtrim($setDirectory, '/');
        $this->taxonomyType = strtolower(trim($taxonomyType));
    }

    // -------------------------------------------------------------------------
    // Static normalization helpers (formerly TaxonomySetRecordPolicy)
    // -------------------------------------------------------------------------

    /**
     * Normalizes a raw set id value to an integer or null.
     *
     * @param mixed $value     Raw value to parse; accepts any scalar or null.
     * @param bool  $allowAll  When true, the ALL_SET_ID sentinel (0) is returned as-is instead of being rejected.
     * @return int|null        Validated set id, or null if the value is absent/invalid.
     */
    public static function normalizeSetId(mixed $value, bool $allowAll = false): ?int
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
        if ($allowAll && $id === self::ALL_SET_ID) {
            return self::ALL_SET_ID;
        }

        return $id >= self::DEFAULT_SET_ID ? $id : null;
    }

    /**
     * Normalizes a raw slug string to lowercase-alphanumeric-hyphen form.
     *
     * @param string $value Raw slug string.
     * @return string       Cleaned slug, max 160 characters; empty string if input collapses to nothing.
     */
    public static function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return substr($value, 0, 160);
    }

    /**
     * Returns whether a slug string meets the minimum slug format requirements.
     *
     * @param string $value Slug to test.
     * @return bool         True when the slug is non-empty and contains only lowercase alphanumerics and hyphens.
     */
    public static function isValidSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim($value))) === 1;
    }

    /**
     * Normalizes a mixed set-selection value into a sorted array of integer set ids.
     *
     * @param mixed $value      Raw selection; accepts an array, scalar, or null.
     * @param bool  $defaultAll When true and the effective selection is empty, returns [ALL_SET_ID] instead of [].
     * @return array<int, int|string> Sorted array of set ids, or [ALL_SET_ID] for an all-sets selection.
     */
    public static function normalizeSelection(mixed $value, bool $defaultAll = true): array
    {
        $items = is_array($value) ? $value : [$value];
        $selection = [];

        foreach ($items as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $normalized = strtolower(trim((string) ($item ?? '')));
            if ($normalized === '') {
                continue;
            }

            if ($normalized === 'all') {
                return [self::ALL_SET_ID];
            }

            if (preg_match('/^\d+$/', $normalized) !== 1) {
                continue;
            }

            $setId = (int) $normalized;
            if ($setId === self::ALL_SET_ID) {
                return [self::ALL_SET_ID];
            }

            if ($setId < self::DEFAULT_SET_ID) {
                continue;
            }

            $selection[$setId] = $setId;
        }

        if ($selection === []) {
            return $defaultAll ? [self::ALL_SET_ID] : [];
        }

        ksort($selection, SORT_NUMERIC);
        return array_values($selection);
    }

    /**
     * Returns whether a set-selection array includes the all-sets sentinel.
     *
     * @param array<int, int|string> $selection Selection array as returned by normalizeSelection().
     * @return bool                             True when the selection covers all sets.
     */
    public static function selectionIncludesAll(array $selection): bool
    {
        foreach ($selection as $item) {
            if (self::normalizeSetId($item, true) === self::ALL_SET_ID) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the display name for the default taxonomy set of a given type.
     *
     * @param string $taxonomyType Taxonomy type (e.g. 'tag' or 'category').
     * @return string              Human-readable default set name.
     */
    public static function defaultSetName(string $taxonomyType): string
    {
        return strtolower(trim($taxonomyType)) === 'tag'
            ? 'Default Tag Set'
            : 'Default Category Set';
    }

    /**
     * Returns the description for the default taxonomy set of a given type.
     *
     * @param string $taxonomyType Taxonomy type (e.g. 'tag' or 'category').
     * @return string              Human-readable default set description.
     */
    public static function defaultSetDescription(string $taxonomyType): string
    {
        return strtolower(trim($taxonomyType)) === 'tag'
            ? 'If you do not configure a tag set, one will be provided for you.'
            : 'If you do not configure a category set, one will be provided for you.';
    }

    // -------------------------------------------------------------------------
    // Instance filesystem store (formerly TaxonomySetFileStoreService)
    // -------------------------------------------------------------------------

    /**
     * Creates the set directory if it does not already exist.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    public function ensureDirectory(): void
    {
        if (is_dir($this->setDirectory)) {
            return;
        }

        if (!@mkdir($this->setDirectory, 0775, true) && !is_dir($this->setDirectory)) {
            throw new RuntimeException('Failed to initialize taxonomy set directory.');
        }
    }

    /**
     * Returns a sorted list of all set file paths in the store directory.
     *
     * Normalizes the storage layout before listing so stale/renamed files are
     * canonicalized first.
     *
     * @return array<int, string> Absolute file paths sorted by set id ascending.
     */
    public function listSetFilePaths(): array
    {
        $this->ensureDirectory();
        $this->normalizeStorageLayout();
        $paths = $this->rawSetFilePaths();
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
     * @param int    $id   Set id.
     * @param string $slug Set slug.
     * @return string      Absolute path where this set's PHP file should live.
     */
    public function pathForRecord(int $id, string $slug): string
    {
        $safeId = max(0, $id);
        $safeSlug = self::normalizeSlug($slug);
        if ($safeSlug === '') {
            $safeSlug = 'set-' . $safeId;
        }

        return $this->setDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
    }

    /**
     * Loads the raw data array for a set by its id, or an empty array if not found.
     *
     * @param int $id Set id to look up.
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
     * Loads the raw data array for a set by its slug, or an empty array if not found.
     *
     * @param string $slug Set slug to look up.
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
     * Loads the raw PHP-array payload from a set file at the given path.
     *
     * @param string $path Absolute path to the set PHP file.
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
     * Loads a set record from a file path, returning the id and raw data as a pair.
     *
     * @param string $path Absolute path to the set PHP file.
     * @return array{id: int, raw: array<string, mixed>}|null Record pair, or null if the file is missing or unrecognizable.
     */
    public function loadRecordFromPath(string $path): ?array
    {
        $raw = $this->loadRawByPath($path);
        if ($raw === []) {
            return null;
        }

        $recordId = $this->recordIdFromRaw($raw, $path);
        if ($recordId === null) {
            return null;
        }

        return [
            'id' => $recordId,
            'raw' => $raw,
        ];
    }

    /**
     * Atomically writes a set record to disk, removing any stale duplicate paths for the same id.
     *
     * @param int                  $id     Set id to write.
     * @param array<string, mixed> $record Record data array to persist.
     * @throws RuntimeException When the file cannot be written or renamed into place.
     */
    public function writeRecordById(int $id, array $record): void
    {
        $this->ensureDirectory();
        $slug = $this->recordSlugFromRaw($record, $id, $id === self::DEFAULT_SET_ID ? self::DEFAULT_SET_SLUG : '');
        $path = $this->pathForRecord($id, $slug);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($record, true) . ";\n";

        // Write to a temp file first so the final rename is atomic.
        $tmpPath = $path . '.tmp';
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write taxonomy set file.');
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize taxonomy set file.');
        }

        $this->invalidatePhpFileCache($tmpPath);
        $this->invalidatePhpFileCache($path);

        // Remove any stale files that matched the same id but had a different path.
        foreach ($this->candidatePathsForId($id) as $candidatePath) {
            if ($candidatePath === $path || !is_file($candidatePath)) {
                continue;
            }

            @unlink($candidatePath);
            $this->invalidatePhpFileCache($candidatePath);
        }
    }

    /**
     * Deletes all set files on disk that belong to the given set id.
     *
     * @param int $id Set id whose files should be removed.
     */
    public function deleteById(int $id): void
    {
        foreach ($this->candidatePathsForId($id) as $path) {
            if (!is_file($path)) {
                continue;
            }

            @unlink($path);
            $this->invalidatePhpFileCache($path);
        }
    }

    /**
     * Returns the next available set id (one above the current maximum).
     *
     * @return int Next id that does not yet correspond to any stored set file.
     */
    public function nextAvailableId(): int
    {
        $maxId = 0;
        foreach ($this->listSetFilePaths() as $path) {
            $id = $this->recordIdFromRaw($this->loadRawByPath($path), $path) ?? 0;
            if ($id > $maxId) {
                $maxId = $id;
            }
        }

        return $maxId + 1;
    }

    /**
     * Ensures the default set record exists, writing it if absent or out of date.
     *
     * @param array<string, mixed> $rootRecord Canonical data for the default set.
     */
    public function ensureRootRecord(array $rootRecord): void
    {
        $this->normalizeStorageLayout();
        $path = $this->pathForRecord(self::DEFAULT_SET_ID, self::DEFAULT_SET_SLUG);
        $raw = $this->loadRawById(self::DEFAULT_SET_ID);
        if (is_file($path) && $raw !== [] && !$this->defaultRecordNeedsRewrite($raw)) {
            return;
        }

        $this->writeRecordById(self::DEFAULT_SET_ID, $rootRecord);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns whether the persisted default-set record differs from the expected canonical values.
     *
     * @param array<string, mixed> $raw Currently stored raw data for the default set.
     * @return bool                    True when the record must be rewritten to match canonical values.
     */
    private function defaultRecordNeedsRewrite(array $raw): bool
    {
        return self::normalizeSetId($raw['id'] ?? null) !== self::DEFAULT_SET_ID
            || trim((string) ($raw['name'] ?? '')) !== self::defaultSetName($this->taxonomyType)
            || trim((string) ($raw['description'] ?? '')) !== self::defaultSetDescription($this->taxonomyType)
            || self::normalizeSlug((string) ($raw['slug'] ?? '')) !== self::DEFAULT_SET_SLUG;
    }

    /**
     * Returns all raw file paths in the set directory without sorting or normalization.
     *
     * @return array<int, string> Unsorted absolute file paths.
     */
    private function rawSetFilePaths(): array
    {
        $paths = glob($this->setDirectory . '/*.php') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * Returns all file paths that could belong to the given set id.
     *
     * Checks both the canonical filename pattern and the stored id field inside each file.
     *
     * @param int $id Set id to search for.
     * @return array<int, string> Deduplicated list of matching absolute paths.
     */
    private function candidatePathsForId(int $id): array
    {
        $this->ensureDirectory();
        $normalizedId = max(0, $id);
        $paths = [];

        foreach (glob($this->setDirectory . '/' . $normalizedId . '_*.php') ?: [] as $path) {
            $paths[] = $path;
        }

        foreach ($this->rawSetFilePaths() as $path) {
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
     * Finds the first file path for a set by id, or null if none exists.
     *
     * @param int $id Set id.
     * @return string|null First matching path, or null.
     */
    private function findPathById(int $id): ?string
    {
        $paths = $this->candidatePathsForId($id);
        return $paths === [] ? null : $paths[0];
    }

    /**
     * Finds the file path for a set by slug, or null if none exists.
     *
     * @param string $slug Set slug.
     * @return string|null Matching path, or null.
     */
    private function findPathBySlug(string $slug): ?string
    {
        $this->ensureDirectory();
        $normalizedSlug = self::normalizeSlug($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        foreach ($this->rawSetFilePaths() as $path) {
            $raw = $this->loadRawByPath($path);
            if ($raw === []) {
                continue;
            }

            $recordId = $this->recordIdFromRaw($raw, $path);
            if ($recordId === null) {
                continue;
            }

            $recordSlug = $this->recordSlugFromRaw($raw, $recordId, basename($path, '.php'));
            if ($recordSlug === $normalizedSlug) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Ensures all stored set files use canonical filenames and canonical field values.
     *
     * Rewrites and renames any file whose stored id, slug, or default-set metadata
     * differs from what the current policy expects.
     */
    private function normalizeStorageLayout(): void
    {
        foreach ($this->rawSetFilePaths() as $path) {
            $raw = $this->loadRawByPath($path);
            if ($raw === []) {
                continue;
            }

            $recordId = $this->recordIdFromRaw($raw, $path);
            if ($recordId === null) {
                continue;
            }

            $recordSlug = $this->recordSlugFromRaw($raw, $recordId, basename($path, '.php'));
            $canonical = $raw;
            $canonical['id'] = $recordId;
            $canonical['slug'] = $recordSlug;
            if ($recordId === self::DEFAULT_SET_ID) {
                $canonical['name'] = self::defaultSetName($this->taxonomyType);
                $canonical['slug'] = self::DEFAULT_SET_SLUG;
                $canonical['description'] = self::defaultSetDescription($this->taxonomyType);
            }

            $targetPath = $this->pathForRecord($recordId, (string) $canonical['slug']);
            $needsRewrite = $path !== $targetPath || $canonical !== $raw;
            if (!$needsRewrite) {
                continue;
            }

            $this->writeRecordById($recordId, $canonical);
            if ($path !== $targetPath && is_file($path)) {
                @unlink($path);
                $this->invalidatePhpFileCache($path);
            }
        }
    }

    /**
     * Resolves the set id for a raw data array, falling back to the filename if the field is absent.
     *
     * @param array<string, mixed> $raw  Raw data loaded from the file.
     * @param string               $path Absolute path of the file (used for fallback id extraction).
     * @return int|null                  Resolved set id, or null if it cannot be determined.
     */
    private function recordIdFromRaw(array $raw, string $path): ?int
    {
        $rawId = self::normalizeSetId($raw['id'] ?? null, true);
        $filenameId = self::filenameId($path);

        // Remap legacy ALL_SET_ID (0) filenames to the canonical default-set id.
        if ($rawId === self::ALL_SET_ID || $filenameId === self::ALL_SET_ID) {
            return self::DEFAULT_SET_ID;
        }

        if ($rawId !== null) {
            return $rawId;
        }

        if ($filenameId >= self::DEFAULT_SET_ID) {
            return $filenameId;
        }

        return null;
    }

    /**
     * Derives the canonical slug for a record, falling back through filename and name heuristics.
     *
     * @param array<string, mixed> $raw      Raw data loaded from the file.
     * @param int                  $id       Resolved set id.
     * @param string               $fallback Basename (without .php) to try as a slug source.
     * @return string                        Canonical slug string.
     */
    private function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        if ($id === self::DEFAULT_SET_ID) {
            return self::DEFAULT_SET_SLUG;
        }

        $slug = self::normalizeSlug((string) ($raw['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = self::normalizeSlug((string) ($matches[1] ?? ''));
            if ($slug !== '') {
                return $slug;
            }
        }

        $slug = self::normalizeSlug($fallback);
        if ($slug !== '' && preg_match('/^\d+$/', $slug) !== 1) {
            return $slug;
        }

        $nameSlug = self::normalizeSlug((string) ($raw['name'] ?? ''));
        if ($nameSlug !== '') {
            return $nameSlug;
        }

        return 'set-' . $id;
    }

    /**
     * Extracts the numeric id component from a set filename, or -1 if the pattern does not match.
     *
     * @param string $path Absolute or relative path to a set PHP file.
     * @return int         Extracted id, or -1 on no match.
     */
    private static function filenameId(string $path): int
    {
        $basename = basename($path, '.php');
        if (preg_match('/^(\d+)(?:_[a-z0-9-]+)?$/', $basename, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return -1;
    }

    /**
     * Clears the PHP stat cache and OPcache entry for a file path.
     *
     * @param string $path Absolute path to invalidate.
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
