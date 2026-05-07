<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/SetWrite.php
 * Write-side data access for filesystem-backed taxonomy set records.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use Raven\Lib\Parser\SetParser;
use RuntimeException;

/**
 * Save and delete methods for taxonomy set records.
 */
final class SetWrite
{
    private SetRead $read;
    private string $taxonomyType;
    private SetParser $setParser;
    private string $setDirectory;

    /**
     * @param string  $taxonomyType Lowercase taxonomy type ('category' or 'tag').
     * @param string  $setDirectory Absolute path to the directory holding set JSON files.
     * @param SetRead $read         Read-side instance for slug-uniqueness validation during save.
     * @return void
     */
    public function __construct(string $taxonomyType, string $setDirectory, SetRead $read)
    {
        $this->read = $read;
        $this->taxonomyType = strtolower(trim($taxonomyType));
        $this->setParser = new SetParser($setDirectory, $this->taxonomyType);
        $this->setDirectory = rtrim($setDirectory, '/');
    }

    /**
     * Creates or updates one taxonomy set record and returns its id.
     *
     * @param array<string, mixed> $data Set fields; 'name' and a valid 'slug' are required for non-default sets.
     * @return int The saved (or assigned) taxonomy set id.
     * @throws RuntimeException When required fields are missing or the slug conflicts with another set.
     */
    public function save(array $data): int
    {
        $providedId = SetParser::normalizeSetId($data['id'] ?? null);
        $setId = $providedId ?? $this->setParser->nextAvailableId();
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $slug = SetParser::normalizeSlug((string) ($data['slug'] ?? ''));

        if ($setId === SetParser::DEFAULT_SET_ID) {
            $name = SetParser::defaultSetName($this->taxonomyType);
            $slug = SetParser::DEFAULT_SET_SLUG;
            $description = SetParser::defaultSetDescription($this->taxonomyType);
        }

        if ($name === '' || !SetParser::isValidSlug($slug)) {
            throw new RuntimeException('Set name and valid slug are required.');
        }

        foreach ($this->read->listAll() as $existing) {
            $existingId = (int) ($existing['id'] ?? -1);
            if ($existingId === $setId) {
                continue;
            }

            if (strtolower(trim((string) ($existing['slug'] ?? ''))) === $slug) {
                throw new RuntimeException('A ' . $this->taxonomyType . ' set with that slug already exists.');
            }
        }

        $existing = $this->read->findById($setId);
        $createdAt = trim((string) ($existing['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => $setId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_stock' => $setId === SetParser::DEFAULT_SET_ID,
            'created_at' => $createdAt,
        ];

        self::writeRecordById($this->setDirectory, $this->taxonomyType, $setId, $record);
        $this->read->clearCache();
        return $setId;
    }

    /**
     * Deletes one taxonomy set record by id.
     *
     * @param int $id Taxonomy set id to delete.
     * @throws RuntimeException When attempting to delete the stock default set.
     * @return void
     */
    public function deleteById(int $id): void
    {
        if ($id === SetParser::DEFAULT_SET_ID) {
            throw new RuntimeException('The stock default set cannot be deleted.');
        }

        self::deleteRecordById($this->setDirectory, $this->taxonomyType, $id);
        $this->read->clearCache();
    }

    /**
     * Ensures all stored set files use canonical filenames and canonical field values.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param string $taxonomyType Taxonomy type label (e.g. 'category' or 'tag').
     * @throws RuntimeException When the directory or one rewritten file cannot be persisted.
     * @return void
     */
    public static function normalizeStorageLayout(string $setDirectory, string $taxonomyType): void
    {
        $normalizedDir = rtrim($setDirectory, '/');
        $normalizedTaxonomy = strtolower(trim($taxonomyType));

        foreach (self::rawSetFilePaths($normalizedDir) as $path) {
            $raw = self::loadRawByPath($path);
            if ($raw === []) {
                continue;
            }

            $recordId = self::recordIdFromRaw($raw, $path);
            if ($recordId === null) {
                continue;
            }

            $recordSlug = self::recordSlugFromRaw($raw, $recordId, basename($path, '.php'));
            $canonical = $raw;
            $canonical['id'] = $recordId;
            $canonical['slug'] = $recordSlug;
            if ($recordId === SetParser::DEFAULT_SET_ID) {
                $canonical['name'] = SetParser::defaultSetName($normalizedTaxonomy);
                $canonical['slug'] = SetParser::DEFAULT_SET_SLUG;
                $canonical['description'] = SetParser::defaultSetDescription($normalizedTaxonomy);
            }

            $targetPath = self::pathForRecord($normalizedDir, $recordId, (string) $canonical['slug']);
            $needsRewrite = $path !== $targetPath || $canonical !== $raw;
            if (!$needsRewrite) {
                continue;
            }

            self::writeRecordById($normalizedDir, $normalizedTaxonomy, $recordId, $canonical);
            if ($path !== $targetPath && is_file($path)) {
                @unlink($path);
                self::invalidatePhpFileCache($path);
            }
        }
    }

    /**
     * Atomically writes one set record to disk and removes stale duplicate paths for the same id.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param string $taxonomyType Taxonomy type label.
     * @param int $id Set id to write.
     * @param array<string, mixed> $record Record data array to persist.
     * @throws RuntimeException When the file cannot be written or renamed into place.
     * @return void
     */
    public static function writeRecordById(string $setDirectory, string $taxonomyType, int $id, array $record): void
    {
        $normalizedDir = rtrim($setDirectory, '/');
        $normalizedTaxonomy = strtolower(trim($taxonomyType));

        self::ensureDirectory($normalizedDir);
        $slug = self::recordSlugFromRaw(
            $record,
            $id,
            $id === SetParser::DEFAULT_SET_ID ? SetParser::DEFAULT_SET_SLUG : ''
        );
        $path = self::pathForRecord($normalizedDir, $id, $slug);
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

        self::invalidatePhpFileCache($tmpPath);
        self::invalidatePhpFileCache($path);

        // Remove any stale files that matched the same id but had a different path.
        foreach (self::candidatePathsForId($normalizedDir, $id) as $candidatePath) {
            if ($candidatePath === $path || !is_file($candidatePath)) {
                continue;
            }

            @unlink($candidatePath);
            self::invalidatePhpFileCache($candidatePath);
        }
    }

    /**
     * Deletes all set files on disk that belong to the given set id.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param string $taxonomyType Taxonomy type label.
     * @param int $id Set id whose files should be removed.
     * @return void
     */
    public static function deleteRecordById(string $setDirectory, string $taxonomyType, int $id): void
    {
        $normalizedDir = rtrim($setDirectory, '/');
        foreach (self::candidatePathsForId($normalizedDir, $id) as $path) {
            if (!is_file($path)) {
                continue;
            }

            @unlink($path);
            self::invalidatePhpFileCache($path);
        }
    }

    /**
     * Ensures the stock default set exists with canonical field values.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param string $taxonomyType Taxonomy type label.
     * @param array<string, mixed> $rootRecord Canonical data for the default set.
     * @throws RuntimeException When the directory or one rewritten file cannot be persisted.
     * @return void
     */
    public static function ensureRootRecord(string $setDirectory, string $taxonomyType, array $rootRecord): void
    {
        $normalizedDir = rtrim($setDirectory, '/');
        $normalizedTaxonomy = strtolower(trim($taxonomyType));

        self::normalizeStorageLayout($normalizedDir, $normalizedTaxonomy);
        $path = self::pathForRecord($normalizedDir, SetParser::DEFAULT_SET_ID, SetParser::DEFAULT_SET_SLUG);
        $raw = self::loadRawById($normalizedDir, SetParser::DEFAULT_SET_ID);
        if (is_file($path) && $raw !== [] && !self::defaultRecordNeedsRewrite($normalizedTaxonomy, $raw)) {
            return;
        }

        self::writeRecordById($normalizedDir, $normalizedTaxonomy, SetParser::DEFAULT_SET_ID, $rootRecord);
    }

    /**
     * Creates the set directory if it does not already exist.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @throws RuntimeException When the directory cannot be created.
     * @return void
     */
    private static function ensureDirectory(string $setDirectory): void
    {
        if (is_dir($setDirectory)) {
            return;
        }

        if (!@mkdir($setDirectory, 0775, true) && !is_dir($setDirectory)) {
            throw new RuntimeException('Failed to initialize taxonomy set directory.');
        }
    }

    /**
     * Returns the canonical file path for a record given its id and slug.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param int $id Set id.
     * @param string $slug Set slug.
     * @return string Absolute path where this set's PHP file should live.
     */
    private static function pathForRecord(string $setDirectory, int $id, string $slug): string
    {
        $safeId = max(0, $id);
        $safeSlug = SetParser::normalizeSlug($slug);
        if ($safeSlug === '') {
            $safeSlug = 'set-' . $safeId;
        }

        return $setDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
    }

    /**
     * Loads the raw data array for a set by its id, or an empty array if not found.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param int $id Set id to look up.
     * @return array<string, mixed> Raw record data, or [] when the record does not exist.
     */
    private static function loadRawById(string $setDirectory, int $id): array
    {
        $path = self::findPathById($setDirectory, $id);
        if ($path === null) {
            return [];
        }

        return self::loadRawByPath($path);
    }

    /**
     * Loads the raw PHP-array payload from a set file at the given path.
     *
     * @param string $path Absolute path to the set PHP file.
     * @return array<string, mixed> Deserialized record data, or [] on missing/invalid file.
     */
    private static function loadRawByPath(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        self::invalidatePhpFileCache($path);

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
     * @param string $taxonomyType Taxonomy type label.
     * @param array<string, mixed> $raw Currently stored raw data for the default set.
     * @return bool True when the record must be rewritten to match canonical values.
     */
    private static function defaultRecordNeedsRewrite(string $taxonomyType, array $raw): bool
    {
        return SetParser::normalizeSetId($raw['id'] ?? null) !== SetParser::DEFAULT_SET_ID
            || trim((string) ($raw['name'] ?? '')) !== SetParser::defaultSetName($taxonomyType)
            || trim((string) ($raw['description'] ?? '')) !== SetParser::defaultSetDescription($taxonomyType)
            || SetParser::normalizeSlug((string) ($raw['slug'] ?? '')) !== SetParser::DEFAULT_SET_SLUG;
    }

    /**
     * Returns all raw file paths in the set directory without sorting or normalization.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @return array<int, string> Unsorted absolute file paths.
     */
    private static function rawSetFilePaths(string $setDirectory): array
    {
        $paths = glob($setDirectory . '/*.php') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * Returns all file paths that could belong to the given set id.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param int $id Set id to search for.
     * @return array<int, string> Deduplicated list of matching absolute paths.
     */
    private static function candidatePathsForId(string $setDirectory, int $id): array
    {
        $normalizedId = max(0, $id);
        $paths = [];

        foreach (glob($setDirectory . '/' . $normalizedId . '_*.php') ?: [] as $path) {
            $paths[] = $path;
        }

        foreach (self::rawSetFilePaths($setDirectory) as $path) {
            if (in_array($path, $paths, true)) {
                continue;
            }

            $raw = self::loadRawByPath($path);
            if ((self::recordIdFromRaw($raw, $path) ?? -1) === $normalizedId) {
                $paths[] = $path;
            }
        }

        sort($paths, SORT_STRING);
        return array_values(array_unique($paths));
    }

    /**
     * Returns the first matching file path for a set id.
     *
     * @param string $setDirectory Absolute path to the set directory.
     * @param int $id Set id to locate.
     * @return string|null Absolute file path, or null when not found.
     */
    private static function findPathById(string $setDirectory, int $id): ?string
    {
        $candidates = self::candidatePathsForId($setDirectory, $id);
        return $candidates[0] ?? null;
    }

    /**
     * Extracts a set id from raw payload/path context.
     *
     * @param array<string, mixed> $raw Raw set payload.
     * @param string $path Source file path used as fallback id source.
     * @return int|null Normalized set id, or null when unavailable.
     */
    private static function recordIdFromRaw(array $raw, string $path): ?int
    {
        $idFromRaw = SetParser::normalizeSetId($raw['id'] ?? null);
        if ($idFromRaw !== null) {
            return $idFromRaw;
        }

        $basename = basename($path, '.php');
        $parts = explode('_', $basename, 2);
        return SetParser::normalizeSetId($parts[0] ?? null);
    }

    /**
     * Extracts or derives a normalized set slug.
     *
     * @param array<string, mixed> $raw Raw set payload.
     * @param int $id Set id.
     * @param string $fallback Fallback slug source.
     * @return string Normalized non-empty slug.
     */
    private static function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        $slug = SetParser::normalizeSlug((string) ($raw['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $fallbackSlug = SetParser::normalizeSlug($fallback);
        if ($fallbackSlug !== '') {
            return $fallbackSlug;
        }

        return $id === SetParser::DEFAULT_SET_ID ? SetParser::DEFAULT_SET_SLUG : ('set-' . max(0, $id));
    }

    /**
     * Clears filesystem and OPcache metadata for one PHP file path.
     *
     * @param string $path Absolute file path.
     * @return void
     */
    private static function invalidatePhpFileCache(string $path): void
    {
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
