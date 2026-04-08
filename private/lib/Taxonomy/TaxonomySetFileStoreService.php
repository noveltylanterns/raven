<?php

declare(strict_types=1);

namespace Raven\Lib\Taxonomy;

use RuntimeException;

/**
 * Shared filesystem persistence helper for taxonomy set PHP files.
 */
final class TaxonomySetFileStoreService
{
    private string $setDirectory;
    private string $taxonomyType;

    public function __construct(string $setDirectory, string $taxonomyType)
    {
        $this->setDirectory = rtrim($setDirectory, '/');
        $this->taxonomyType = strtolower(trim($taxonomyType));
    }

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
     * @return array<int, string>
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

    public function pathForRecord(int $id, string $slug): string
    {
        $safeId = max(0, $id);
        $safeSlug = TaxonomySetRecordPolicy::normalizeSlug($slug);
        if ($safeSlug === '') {
            $safeSlug = 'set-' . $safeId;
        }

        return $this->setDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
    }

    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     * @return array{id: int, raw: array<string, mixed>}|null
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
     * @param array<string, mixed> $record
     */
    public function writeRecordById(int $id, array $record): void
    {
        $this->ensureDirectory();
        $slug = $this->recordSlugFromRaw($record, $id, $id === TaxonomySetRecordPolicy::DEFAULT_SET_ID ? TaxonomySetRecordPolicy::DEFAULT_SET_SLUG : '');
        $path = $this->pathForRecord($id, $slug);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($record, true) . ";\n";

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

        foreach ($this->candidatePathsForId($id) as $candidatePath) {
            if ($candidatePath === $path || !is_file($candidatePath)) {
                continue;
            }

            @unlink($candidatePath);
            $this->invalidatePhpFileCache($candidatePath);
        }
    }

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
     * @param array<string, mixed> $rootRecord
     */
    public function ensureRootRecord(array $rootRecord): void
    {
        $this->normalizeStorageLayout();
        $path = $this->pathForRecord(TaxonomySetRecordPolicy::DEFAULT_SET_ID, TaxonomySetRecordPolicy::DEFAULT_SET_SLUG);
        $raw = $this->loadRawById(TaxonomySetRecordPolicy::DEFAULT_SET_ID);
        if (is_file($path) && $raw !== [] && !$this->defaultRecordNeedsRewrite($raw)) {
            return;
        }

        $this->writeRecordById(TaxonomySetRecordPolicy::DEFAULT_SET_ID, $rootRecord);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function defaultRecordNeedsRewrite(array $raw): bool
    {
        return TaxonomySetRecordPolicy::normalizeSetId($raw['id'] ?? null) !== TaxonomySetRecordPolicy::DEFAULT_SET_ID
            || trim((string) ($raw['name'] ?? '')) !== TaxonomySetRecordPolicy::defaultSetName($this->taxonomyType)
            || trim((string) ($raw['description'] ?? '')) !== TaxonomySetRecordPolicy::defaultSetDescription($this->taxonomyType)
            || TaxonomySetRecordPolicy::normalizeSlug((string) ($raw['slug'] ?? '')) !== TaxonomySetRecordPolicy::DEFAULT_SET_SLUG;
    }

    /**
     * @return array<int, string>
     */
    private function rawSetFilePaths(): array
    {
        $paths = glob($this->setDirectory . '/*.php') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return array<int, string>
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

    private function findPathById(int $id): ?string
    {
        $paths = $this->candidatePathsForId($id);
        return $paths === [] ? null : $paths[0];
    }

    private function findPathBySlug(string $slug): ?string
    {
        $this->ensureDirectory();
        $normalizedSlug = TaxonomySetRecordPolicy::normalizeSlug($slug);
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
            if ($recordId === TaxonomySetRecordPolicy::DEFAULT_SET_ID) {
                $canonical['name'] = TaxonomySetRecordPolicy::defaultSetName($this->taxonomyType);
                $canonical['slug'] = TaxonomySetRecordPolicy::DEFAULT_SET_SLUG;
                $canonical['description'] = TaxonomySetRecordPolicy::defaultSetDescription($this->taxonomyType);
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

    private function recordIdFromRaw(array $raw, string $path): ?int
    {
        $rawId = TaxonomySetRecordPolicy::normalizeSetId($raw['id'] ?? null, true);
        $filenameId = self::filenameId($path);

        if ($rawId === TaxonomySetRecordPolicy::ALL_SET_ID || $filenameId === TaxonomySetRecordPolicy::ALL_SET_ID) {
            return TaxonomySetRecordPolicy::DEFAULT_SET_ID;
        }

        if ($rawId !== null) {
            return $rawId;
        }

        if ($filenameId >= TaxonomySetRecordPolicy::DEFAULT_SET_ID) {
            return $filenameId;
        }

        return null;
    }

    private function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        if ($id === TaxonomySetRecordPolicy::DEFAULT_SET_ID) {
            return TaxonomySetRecordPolicy::DEFAULT_SET_SLUG;
        }

        $slug = TaxonomySetRecordPolicy::normalizeSlug((string) ($raw['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = TaxonomySetRecordPolicy::normalizeSlug((string) ($matches[1] ?? ''));
            if ($slug !== '') {
                return $slug;
            }
        }

        $slug = TaxonomySetRecordPolicy::normalizeSlug($fallback);
        if ($slug !== '' && preg_match('/^\d+$/', $slug) !== 1) {
            return $slug;
        }

        $nameSlug = TaxonomySetRecordPolicy::normalizeSlug((string) ($raw['name'] ?? ''));
        if ($nameSlug !== '') {
            return $nameSlug;
        }

        return 'set-' . $id;
    }

    private static function filenameId(string $path): int
    {
        $basename = basename($path, '.php');
        if (preg_match('/^(\d+)(?:_[a-z0-9-]+)?$/', $basename, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return -1;
    }

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
