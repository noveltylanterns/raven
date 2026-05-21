<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/SetParser.php
 * Taxonomy set record normalization policy and filesystem read helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

/**
 * Combined normalization policy and filesystem reader for taxonomy set records.
 *
 * Static methods carry shared constants and data-normalization rules; instance
 * methods handle read-side loading of the PHP-file-backed set store on disk.
 * Writes and storage-layout repair live in `Raven\Core\Repository\SetWrite`.
 */
final class SetParser
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
     * Prepares the reader for a given set directory and taxonomy type.
     *
     * @param string $setDirectory Absolute path to the directory containing set PHP files.
     * @param string $taxonomyType Taxonomy type label (e.g. 'category', 'tag'); used when generating default-set names.
     * @return void
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
        // Reject non-scalar inputs early so nested payloads cannot coerce into ids.
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        // Null means "no selection" and remains nullable for callers.
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        // Ids must be integer-like strings.
        if ($normalized === '' || preg_match('/^-?\d+$/', $normalized) !== 1) {
            return null;
        }

        $id = (int) $normalized;
        // Preserve the all-sets sentinel only when the caller explicitly allows it.
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

        // Normalize each candidate into a validated set id, preserving the all-sets shortcut.
        foreach ($items as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $normalized = strtolower(trim((string) ($item ?? '')));
            // Skip blank tokens from sparse request arrays.
            if ($normalized === '') {
                continue;
            }

            // String sentinel `all` short-circuits to all-sets selection.
            if ($normalized === 'all') {
                return [self::ALL_SET_ID];
            }

            // Numeric tokens only; non-numeric slugs are not valid set selectors.
            if (preg_match('/^\d+$/', $normalized) !== 1) {
                continue;
            }

            $setId = (int) $normalized;
            // Numeric zero is equivalent to the all-sets sentinel.
            if ($setId === self::ALL_SET_ID) {
                return [self::ALL_SET_ID];
            }

            // Ignore ids below the persisted default set id floor.
            if ($setId < self::DEFAULT_SET_ID) {
                continue;
            }

            $selection[$setId] = $setId;
        }

        // Empty normalized selections either fallback to all-sets or remain empty by caller policy.
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
        // Scan normalized values for the explicit all-sets sentinel.
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
    // Instance filesystem reads
    // -------------------------------------------------------------------------

    /**
     * Returns a sorted list of all set file paths in the store directory.
     *
     * @return array<int, string> Absolute file paths sorted by set id ascending.
     */
    public function listSetFilePaths(): array
    {
        $paths = $this->rawSetFilePaths();
        usort($paths, static function (string $left, string $right): int {
            $leftId = self::filenameId($left);
            $rightId = self::filenameId($right);
            // Primary sort key is numeric id so set ordering remains deterministic across renames.
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
        // Synthesize a deterministic slug when normalization collapses to empty.
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
        // Missing set ids resolve to an empty payload rather than throwing.
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
        // Unknown slugs resolve to an empty payload for read-side convenience.
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
        // Only existing files are executable payload candidates.
        if (!is_file($path)) {
            return [];
        }

        $this->invalidatePhpFileCache($path);

        // Guard require-time parse/runtime errors so corrupted set files fail closed.
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
        // Empty payload means unreadable or missing record.
        if ($raw === []) {
            return null;
        }

        $recordId = $this->recordIdFromRaw($raw, $path);
        // Records without resolvable ids are ignored as invalid.
        if ($recordId === null) {
            return null;
        }

        return [
            'id' => $recordId,
            'raw' => $raw,
        ];
    }

    /**
     * Returns the next available set id (one above the current maximum).
     *
     * @return int Next id that does not yet correspond to any stored set file.
     */
    public function nextAvailableId(): int
    {
        $maxId = 0;
        // Walk persisted files to find the highest assigned id.
        foreach ($this->listSetFilePaths() as $path) {
            $id = $this->recordIdFromRaw($this->loadRawByPath($path), $path) ?? 0;
            // Track the running maximum for next-id allocation.
            if ($id > $maxId) {
                $maxId = $id;
            }
        }

        return $maxId + 1;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
        $normalizedId = max(0, $id);
        $paths = [];

        // Fast path: canonical id-prefixed filenames.
        foreach (glob($this->setDirectory . '/' . $normalizedId . '_*.php') ?: [] as $path) {
            $paths[] = $path;
        }

        // Slow path: inspect every file for embedded id values to catch legacy/misaligned names.
        foreach ($this->rawSetFilePaths() as $path) {
            // Skip files already captured by canonical glob.
            if (in_array($path, $paths, true)) {
                continue;
            }

            $raw = $this->loadRawByPath($path);
            // Include files whose embedded id matches even when filename prefix does not.
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
        $normalizedSlug = self::normalizeSlug($slug);
        // Empty normalized slugs cannot match persisted records.
        if ($normalizedSlug === '') {
            return null;
        }

        // Scan records and compare canonical slug derivations.
        foreach ($this->rawSetFilePaths() as $path) {
            $raw = $this->loadRawByPath($path);
            // Ignore unreadable/invalid record payloads.
            if ($raw === []) {
                continue;
            }

            $recordId = $this->recordIdFromRaw($raw, $path);
            // Slug derivation requires a valid record id.
            if ($recordId === null) {
                continue;
            }

            $recordSlug = $this->recordSlugFromRaw($raw, $recordId, basename($path, '.php'));
            // Return first slug match in sorted path order.
            if ($recordSlug === $normalizedSlug) {
                return $path;
            }
        }

        return null;
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

        // Prefer explicit persisted ids over filename-derived ids when available.
        if ($rawId !== null) {
            return $rawId;
        }

        // Fallback to filename-derived id when it meets the valid id floor.
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
        // Default set slug is fixed by contract and ignores file/raw variants.
        if ($id === self::DEFAULT_SET_ID) {
            return self::DEFAULT_SET_SLUG;
        }

        $slug = self::normalizeSlug((string) ($raw['slug'] ?? ''));
        // Prefer explicit persisted slug when available.
        if ($slug !== '') {
            return $slug;
        }

        // Try extracting slug portion from canonical `{id}_{slug}` fallback filenames.
        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = self::normalizeSlug((string) ($matches[1] ?? ''));
            // Use extracted fallback slug when it normalizes cleanly.
            if ($slug !== '') {
                return $slug;
            }
        }

        $slug = self::normalizeSlug($fallback);
        // Reject pure-numeric fallback slugs so ids are not mistaken for canonical slugs.
        if ($slug !== '' && preg_match('/^\d+$/', $slug) !== 1) {
            return $slug;
        }

        $nameSlug = self::normalizeSlug((string) ($raw['name'] ?? ''));
        // Use normalized set name as a late slug fallback.
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
        // Accept canonical id or id_slug filename patterns.
        if (preg_match('/^(\d+)(?:_[a-z0-9-]+)?$/', $basename, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return -1;
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
        // Ignore empty invalidation requests from defensive callers.
        if ($normalized === '') {
            return;
        }

        clearstatcache(true, $normalized);
        // Invalidate OPcache entries so requires reflect just-written set file changes.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($normalized, true);
        }
    }

}
