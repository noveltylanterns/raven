<?php

declare(strict_types=1);

namespace Raven\Lib\Channel;

use Raven\Lib\Routing\ChannelRecordPolicy;
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
        $paths = glob($this->channelDirectory . '/*.php') ?: [];
        sort($paths, SORT_STRING);
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

    public function pathForSlug(string $slug): string
    {
        return $this->channelDirectory . '/' . strtolower(trim($slug)) . '.php';
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRawBySlug(string $slug): array
    {
        $path = $this->pathForSlug($slug);
        if (!is_file($path)) {
            return [];
        }

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
    public function loadRecordFromPath(string $path, string $slug): ?array
    {
        try {
            /** @var mixed $raw */
            $raw = require $path;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($raw)) {
            return null;
        }

        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }
        $createdAt = trim((string) ($raw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        return [
            'id' => ChannelRecordPolicy::normalizeChannelId($raw['id'] ?? null) ?? 0,
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($raw['description'] ?? '')),
            'text_editor_override' => ChannelRecordPolicy::normalizeTextEditorOverride(
                (string) ($raw['text_editor_override'] ?? 'inherit')
            ),
            'page_route_mode' => ChannelRecordPolicy::normalizePageRouteMode((string) ($raw['page_route_mode'] ?? 'slug')),
            'page_url_separator' => ChannelRecordPolicy::normalizePageUrlSeparator(
                (string) ($raw['page_url_separator'] ?? 'inherit')
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

    /**
     * @param array<string, mixed> $record
     */
    public function writeRecordAtPath(string $path, array $record): void
    {
        $this->ensureDirectory();

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($record, true) . ";\n";

        $tmpPath = $path . '.tmp';
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write channel file.');
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize channel file.');
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    public function writeRecordBySlug(string $slug, array $record): void
    {
        $this->writeRecordAtPath($this->pathForSlug($slug), $record);
    }

    public function persistChannelId(string $slug, int $id): void
    {
        if ($id < 1 || trim($slug) === '') {
            return;
        }

        $raw = $this->loadRawBySlug($slug);
        if ($raw === []) {
            return;
        }

        $currentId = ChannelRecordPolicy::normalizeChannelId($raw['id'] ?? null);
        if ($currentId === $id) {
            return;
        }

        $raw['id'] = $id;
        $this->writeRecordBySlug($slug, $raw);
    }
}
