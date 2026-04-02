<?php

declare(strict_types=1);

namespace Raven\Lib\Channel;

use Raven\Lib\Channel\ChannelRecordPolicy;
use RuntimeException;

/**
 * Shared filesystem persistence helper for channel metadata PHP files.
 */
final class ChannelFileStoreService
{
    private string $channelDirectory;

    public function __construct(string $channelDirectory)
    {
        $this->channelDirectory = rtrim($channelDirectory, '/');
    }

    /**
     * @return array<int, string>
     */
    public function listChannelFilePaths(): array
    {
        $this->ensureDirectory();
        $this->normalizeStorageLayout();
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

    public function ensureDirectory(): void
    {
        if (is_dir($this->channelDirectory)) {
            return;
        }

        if (!@mkdir($this->channelDirectory, 0775, true) && !is_dir($this->channelDirectory)) {
            throw new RuntimeException('Failed to initialize channel directory.');
        }
    }

    public function pathForRecord(int $id, string $slug): string
    {
        $safeId = max(ChannelRecordPolicy::ROOT_CHANNEL_ID, $id);
        $safeSlug = strtolower(trim($slug));
        if (!ChannelRecordPolicy::isValidSlug($safeSlug)) {
            $safeSlug = $safeId === ChannelRecordPolicy::ROOT_CHANNEL_ID
                ? ChannelRecordPolicy::ROOT_CHANNEL_SLUG
                : ('channel-' . $safeId);
        }

        return $this->channelDirectory . '/' . $safeId . '_' . $safeSlug . '.php';
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
     * @return array<string, mixed>|null
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

    /**
     * @param array<string, mixed> $record
     */
    public function writeRecordById(int $id, string $slug, array $record): void
    {
        $this->ensureDirectory();
        $canonical = $this->canonicalizeRecord($id, $slug, $record);
        $path = $this->pathForRecord((int) $canonical['id'], (string) $canonical['slug']);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($canonical, true) . ";\n";

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

        foreach ($this->candidatePathsForId((int) $canonical['id']) as $candidatePath) {
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

            if (!@unlink($path)) {
                throw new RuntimeException('Failed to delete channel file.');
            }
            $this->invalidatePhpFileCache($path);
        }
    }

    public function persistChannelId(string $slug, int $id): void
    {
        if ($id < ChannelRecordPolicy::ROOT_CHANNEL_ID || trim($slug) === '') {
            return;
        }

        $raw = $this->loadRawBySlug($slug);
        if ($raw === []) {
            return;
        }

        $this->writeRecordById($id, $slug, $raw);
    }

    /**
     * @return array<int, string>
     */
    private function rawChannelFilePaths(): array
    {
        $paths = glob($this->channelDirectory . '/*.php') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function candidatePathsForId(int $id): array
    {
        $this->ensureDirectory();
        $normalizedId = max(ChannelRecordPolicy::ROOT_CHANNEL_ID, $id);
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

    private function findPathById(int $id): ?string
    {
        $paths = $this->candidatePathsForId($id);
        return $paths === [] ? null : $paths[0];
    }

    private function findPathBySlug(string $slug): ?string
    {
        $this->ensureDirectory();
        $normalizedSlug = strtolower(trim($slug));
        if (!ChannelRecordPolicy::isValidSlug($normalizedSlug)) {
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

    private function normalizeStorageLayout(): void
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

    private function recordIdFromRaw(array $raw, string $path): ?int
    {
        $rawId = ChannelRecordPolicy::normalizeChannelId($raw['id'] ?? null);
        if ($rawId !== null) {
            return $rawId;
        }

        $filenameId = self::filenameId($path);
        if ($filenameId >= ChannelRecordPolicy::ROOT_CHANNEL_ID) {
            return $filenameId;
        }

        $fallbackSlug = $this->slugFromFilename($path);
        if ($fallbackSlug !== '' && ChannelRecordPolicy::isRootChannelSlug($fallbackSlug)) {
            return ChannelRecordPolicy::ROOT_CHANNEL_ID;
        }

        return null;
    }

    private function recordSlugFromRaw(array $raw, int $id, string $fallback): string
    {
        if ($id === ChannelRecordPolicy::ROOT_CHANNEL_ID) {
            return ChannelRecordPolicy::ROOT_CHANNEL_SLUG;
        }

        $slug = strtolower(trim((string) ($raw['slug'] ?? '')));
        if (ChannelRecordPolicy::isValidSlug($slug)) {
            return $slug;
        }

        if (preg_match('/^\d+_([a-z0-9-]+)$/', $fallback, $matches) === 1) {
            $slug = strtolower(trim((string) ($matches[1] ?? '')));
            if (ChannelRecordPolicy::isValidSlug($slug)) {
                return $slug;
            }
        }

        $slug = $this->slugFromFilename($fallback);
        if ($slug !== '' && ChannelRecordPolicy::isValidSlug($slug) && !preg_match('/^\d+$/', $slug)) {
            return $slug;
        }

        $nameSlug = strtolower(trim((string) ($raw['name'] ?? '')));
        $nameSlug = preg_replace('/[^a-z0-9]+/', '-', $nameSlug) ?? '';
        $nameSlug = trim($nameSlug, '-');
        $nameSlug = preg_replace('/-+/', '-', $nameSlug) ?? '';
        if ($nameSlug !== '' && ChannelRecordPolicy::isValidSlug($nameSlug)) {
            return $nameSlug;
        }

        return 'channel-' . $id;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function canonicalizeRecord(int $id, string $slug, array $raw): array
    {
        $normalizedId = max(ChannelRecordPolicy::ROOT_CHANNEL_ID, $id);
        $normalizedSlug = $this->recordSlugFromRaw($raw, $normalizedId, $slug);
        $name = trim((string) ($raw['name'] ?? ''));
        if ($normalizedId === ChannelRecordPolicy::ROOT_CHANNEL_ID) {
            $name = ChannelRecordPolicy::ROOT_CHANNEL_NAME;
            $normalizedSlug = ChannelRecordPolicy::ROOT_CHANNEL_SLUG;
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
            'feed_enabled' => ChannelRecordPolicy::normalizeFeedEnabled($raw['feed_enabled'] ?? false),
            'category_sets' => ChannelRecordPolicy::normalizeTaxonomySetSelection($raw['category_sets'] ?? [], false),
            'tag_sets' => ChannelRecordPolicy::normalizeTaxonomySetSelection($raw['tag_sets'] ?? [], false),
            'editor_override' => ChannelRecordPolicy::normalizeEditorOverride(
                (string) ($raw['editor_override'] ?? 'inherit')
            ),
            'route_mode' => ChannelRecordPolicy::normalizeRouteMode((string) ($raw['route_mode'] ?? 'inherit')),
            'route_separator' => ChannelRecordPolicy::normalizeRouteSeparator(
                (string) ($raw['route_separator'] ?? 'inherit')
            ),
            'cover_image_path' => ChannelRecordPolicy::normalizeNullablePath($raw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRecordPolicy::normalizeNullablePath($raw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRecordPolicy::normalizeNullablePath($raw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRecordPolicy::normalizeNullablePath($raw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRecordPolicy::normalizeNullablePath($raw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRecordPolicy::normalizeNullablePath($raw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRecordPolicy::normalizeNullablePath($raw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRecordPolicy::normalizeNullablePath($raw['preview_image_lg_path'] ?? null),
            'custom_fields' => is_array($raw['custom_fields'] ?? null) ? $raw['custom_fields'] : [],
            'overrides' => is_array($raw['overrides'] ?? null) ? $raw['overrides'] : [],
            'created_at' => $createdAt,
        ];
    }

    private static function filenameId(string $path): int
    {
        $basename = basename($path, '.php');
        if (preg_match('/^(\d+)(?:_[a-z0-9-]+)?$/', $basename, $matches) === 1) {
            return (int) ($matches[1] ?? -1);
        }

        return -1;
    }

    private function slugFromFilename(string $path): string
    {
        $basename = basename($path, '.php');
        if (preg_match('/^\d+_([a-z0-9-]+)$/', $basename, $matches) === 1) {
            return strtolower(trim((string) ($matches[1] ?? '')));
        }

        return strtolower(trim($basename));
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
