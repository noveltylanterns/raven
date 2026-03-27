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

    public function __construct(string $setDirectory)
    {
        $this->setDirectory = rtrim($setDirectory, '/');
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
        $paths = glob($this->setDirectory . '/*.php') ?: [];
        usort($paths, static function (string $left, string $right): int {
            $leftId = (int) pathinfo($left, PATHINFO_FILENAME);
            $rightId = (int) pathinfo($right, PATHINFO_FILENAME);
            if ($leftId !== $rightId) {
                return $leftId <=> $rightId;
            }

            return strcmp($left, $right);
        });
        return $paths;
    }

    public function pathForId(int $id): string
    {
        return $this->setDirectory . '/' . max(0, $id) . '.php';
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRawById(int $id): array
    {
        $path = $this->pathForId($id);
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
        $basename = basename($path, '.php');
        if ($basename === '' || preg_match('/^\d+$/', $basename) !== 1) {
            return null;
        }

        $recordId = (int) $basename;
        $raw = $this->loadRawById($recordId);
        if ($raw === []) {
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
        $path = $this->pathForId($id);
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
    }

    public function deleteById(int $id): void
    {
        $path = $this->pathForId($id);
        if (!is_file($path)) {
            return;
        }

        @unlink($path);
        $this->invalidatePhpFileCache($path);
    }

    public function nextAvailableId(): int
    {
        $maxId = 0;
        foreach ($this->listSetFilePaths() as $path) {
            $id = (int) pathinfo($path, PATHINFO_FILENAME);
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
        $path = $this->pathForId(TaxonomySetRecordPolicy::ROOT_SET_ID);
        if (is_file($path) && $this->loadRawById(TaxonomySetRecordPolicy::ROOT_SET_ID) !== []) {
            return;
        }

        $this->writeRecordById(TaxonomySetRecordPolicy::ROOT_SET_ID, $rootRecord);
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
