<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/SetScribe.php
 * Taxonomy set filesystem persistence and canonicalization repair helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Lib\Parser\SetParser;
use RuntimeException;

/**
 * Owns on-disk writes for the PHP-file-backed taxonomy set store.
 *
 * Repositories keep read-side normalization in `SetParser`; this class owns
 * directory creation, atomic writes, stale-file cleanup, and storage-layout
 * repair so parser classes can stay read-oriented.
 */
final class SetScribe
{
    /** Absolute path to the directory that holds set PHP files. */
    private string $setDirectory;

    /** Taxonomy type string used when canonicalizing the stock default set. */
    private string $taxonomyType;

    /**
     * Prepares the scribe for one taxonomy set directory.
     *
     * @param string $setDirectory Absolute path to the directory containing set PHP files.
     * @param string $taxonomyType Taxonomy type label (e.g. 'category' or 'tag').
     * @return void
     */
    public function __construct(string $setDirectory, string $taxonomyType)
    {
        $this->setDirectory = rtrim($setDirectory, '/');
        $this->taxonomyType = strtolower(trim($taxonomyType));
    }

    /**
     * Ensures all stored set files use canonical filenames and canonical field values.
     *
     * Rewrites and renames any file whose stored id, slug, or default-set metadata
     * differs from what the current policy expects.
     *
     * @throws RuntimeException When the directory or one rewritten file cannot be persisted.
     * @return void
     */
    public function normalizeStorageLayout(): void
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
            if ($recordId === SetParser::DEFAULT_SET_ID) {
                $canonical['name'] = SetParser::defaultSetName($this->taxonomyType);
                $canonical['slug'] = SetParser::DEFAULT_SET_SLUG;
                $canonical['description'] = SetParser::defaultSetDescription($this->taxonomyType);
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
     * Atomically writes one set record to disk and removes stale duplicate paths for the same id.
     *
     * @param int                  $id     Set id to write.
     * @param array<string, mixed> $record Record data array to persist.
     * @throws RuntimeException When the file cannot be written or renamed into place.
     * @return void
     */
    public function writeRecordById(int $id, array $record): void
    {
        $this->ensureDirectory();
        $slug = $this->recordSlugFromRaw(
            $record,
            $id,
            $id === SetParser::DEFAULT_SET_ID ? SetParser::DEFAULT_SET_SLUG : ''
        );
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
     * @return void
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
     * Ensures the stock default set exists with canonical field values.
     *
     * @param array<string, mixed> $rootRecord Canonical data for the default set.
     * @throws RuntimeException When the directory or one rewritten file cannot be persisted.
     * @return void
     */
    public function ensureRootRecord(array $rootRecord): void
    {
        $this->normalizeStorageLayout();
        $path = $this->pathForRecord(SetParser::DEFAULT_SET_ID, SetParser::DEFAULT_SET_SLUG);
        $raw = $this->loadRawById(SetParser::DEFAULT_SET_ID);
        if (is_file($path) && $raw !== [] && !$this->defaultRecordNeedsRewrite($raw)) {
            return;
        }

        $this->writeRecordById(SetParser::DEFAULT_SET_ID, $rootRecord);
    }

    /**
     * Creates the set directory if it does not already exist.
     *
     * @throws RuntimeException When the directory cannot be created.
     * @return void
     */
    private function ensureDirectory(): void
    {
        if (is_dir($this->setDirectory)) {
            return;
        }

        if (!@mkdir($this->setDirectory, 0775, true) && !is_dir($this->setDirectory)) {
            throw new RuntimeException('Failed to initialize taxonomy set directory.');
        }
    }

    /**
     * Returns the canonical file path for a record given its id and slug.
     *
     * @param int $id Set id.
     * @param string $slug Set slug.
     * @return string Absolute path where this set's PHP file should live.
     */
    private function pathForRecord(int $id, string $slug): string
    {
        $safeId = max(0, $id);
        $safeSlug = SetParser::normalizeSlug($slug);
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
    private function loadRawById(int $id): array
    {
        $path = $this->findPathById($id);
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
     * Returns whether the persisted default-set record differs from the expected canonical values.
     *
     * @param array<string, mixed> $raw Currently stored raw data for the default set.
     * @return bool True when the record must be rewritten to match canonical values.
     */
    private function defaultRecordNeedsRewrite(array $raw): bool
    {
        return SetParser::normalizeSetId($raw['id'] ?? null) !== SetParser::DEFAULT_SET_ID
            || trim((string) ($raw['name'] ?? '')) !== SetParser::defaultSetName($this->taxonomyType)
            || trim((string) ($raw['description'] ?? '')) !== SetParser::defaultSetDescription($this->taxonomyType)
            || SetParser::normalizeSlug((string) ($raw['slug'] ?? '')) !== SetParser::DEFAULT_SET_SLUG;
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
     * Resolves the set id for a raw data array, falling back to the filename if the field is absent.
     *
     * @param array<string, mixed> $raw Raw data loaded from the file.
     * @param string $path Absolute path of the file.
     * @return int|null Resolved set id, or null if it cannot be determined.
     */
    private function recordIdFromRaw(array $raw, string $path): ?int
    {
        $rawId = SetParser::normalizeSetId($raw['id'] ?? null, true);
        $filenameId = self::filenameId($path);

        // Remap legacy ALL_SET_ID (0) filenames to the canonical default-set id.
        if ($rawId === SetParser::ALL_SET_ID || $filenameId === SetParser::ALL_SET_ID) {
            return SetParser::DEFAULT_SET_ID;
        }

        if ($rawId !== null) {
            return $rawId;
        }

        if ($filenameId >= SetParser::DEFAULT_SET_ID) {
            return $filenameId;
        }

        return null;
    }

    /**
     * Derives the canonical slug for a record, falling back through filename and name heuristics.
     *
     * @param array<string, mixed> $raw Raw data loaded from the file.
     * @param int $id Resolved set id.
     * @param string $fallback Basename (without `.php`) to try as a slug source.
     * @return string Canonical slug string.
     */
    private function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        if ($id === SetParser::DEFAULT_SET_ID) {
            return SetParser::DEFAULT_SET_SLUG;
        }

        $slug = SetParser::normalizeSlug((string) ($raw['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = SetParser::normalizeSlug((string) ($matches[1] ?? ''));
            if ($slug !== '') {
                return $slug;
            }
        }

        $slug = SetParser::normalizeSlug($fallback);
        if ($slug !== '' && preg_match('/^\d+$/', $slug) !== 1) {
            return $slug;
        }

        $nameSlug = SetParser::normalizeSlug((string) ($raw['name'] ?? ''));
        if ($nameSlug !== '') {
            return $nameSlug;
        }

        return 'set-' . $id;
    }

    /**
     * Extracts the numeric id component from a set filename, or -1 if the pattern does not match.
     *
     * @param string $path Absolute or relative path to a set PHP file.
     * @return int Extracted id, or -1 on no match.
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
