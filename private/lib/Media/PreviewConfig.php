<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/PreviewConfig.php
 * Preview/icon and shared taxonomy image config/path helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Shared config and path helpers for taxonomy preview/icon/cover images.
 */
final class PreviewConfig
{
    private Config $config;
    private ImageVariantProcessor $variantProcessor;

    /**
     * @param Config $config Runtime config reader for media policy lookups.
     * @return void
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->variantProcessor = new ImageVariantProcessor($config);
    }

    /**
     * Returns configured media upload extensions.
     *
     * @return array<int, string>
     */
    public function allowedImageExtensions(): array
    {
        $raw = strtolower(trim((string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png')));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $allowed = [];
        foreach ($parts as $part) {
            if ($part === 'jpeg') {
                $part = 'jpg';
            }

            if ($part === '' || preg_match('/^[a-z0-9]+$/', $part) !== 1) {
                continue;
            }

            $allowed[$part] = $part;
        }

        return array_values($allowed);
    }

    /**
     * Returns configured extensions as one panel-facing label.
     *
     * @return string
     */
    public function allowedImageExtensionsLabel(): string
    {
        $allowed = $this->allowedImageExtensions();
        return $allowed === [] ? 'none (uploads disabled)' : implode(', ', $allowed);
    }

    /**
     * Returns configured image max upload size in kilobytes.
     *
     * @return int|null
     */
    public function maxImageFilesizeKb(): ?int
    {
        $bytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        if ($bytes <= 0) {
            return null;
        }

        return (int) max(1, ceil($bytes / 1024));
    }

    /**
     * Returns configured variant sizes.
     *
     * @return array<string, array{width: int, height: int}>
     */
    public function imageVariantSpecs(): array
    {
        return $this->variantProcessor->variantSpecs();
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public function imageStoragePayloadFromRecord(string $taxonomyType, ?array $record): array
    {
        return self::storagePayloadFromRecord($taxonomyType, $record);
    }

    /**
     * @return array<int, string>
     */
    public function imageStorageKeysForSlot(string $taxonomyType, string $slot): array
    {
        return self::storageKeysForSlot($taxonomyType, $slot);
    }

    /**
     * @param array<string, mixed> $storage
     * @return array<string, string|null>
     */
    public function imagePathsFromStoragePayload(string $taxonomyType, int $taxonomyId, array $storage): array
    {
        return self::pathsFromStoragePayload($taxonomyType, $taxonomyId, $storage);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public function imagePathsFromRecord(string $taxonomyType, int $taxonomyId, ?array $record): array
    {
        return self::pathsFromRecord($taxonomyType, $taxonomyId, $record);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public static function storagePayloadFromRecord(string $taxonomyType, ?array $record): array
    {
        if (self::supportsFilenameStorage($taxonomyType)) {
            return [
                'cover_image' => self::normalizeFilename($record['cover_image'] ?? null),
                'preview_image' => self::normalizeFilename($record['preview_image'] ?? null),
                'icon_image' => self::normalizeFilename($record['icon_image'] ?? null),
            ];
        }

        $paths = [];
        foreach ([
            'cover_image_path',
            'cover_image_sm_path',
            'cover_image_md_path',
            'cover_image_lg_path',
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ] as $key) {
            $paths[$key] = self::normalizePath($record[$key] ?? null);
        }

        return $paths;
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public static function pathsFromRecord(string $taxonomyType, int $taxonomyId, ?array $record): array
    {
        return self::pathsFromStoragePayload(
            $taxonomyType,
            $taxonomyId,
            self::storagePayloadFromRecord($taxonomyType, $record)
        );
    }

    /**
     * @param array<string, mixed> $storage
     * @return array<string, string|null>
     */
    public static function pathsFromStoragePayload(string $taxonomyType, int $taxonomyId, array $storage): array
    {
        if (!self::supportsFilenameStorage($taxonomyType)) {
            $paths = [];
            foreach ([
                'cover_image_path',
                'cover_image_sm_path',
                'cover_image_md_path',
                'cover_image_lg_path',
                'preview_image_path',
                'preview_image_sm_path',
                'preview_image_md_path',
                'preview_image_lg_path',
            ] as $key) {
                $paths[$key] = self::normalizePath($storage[$key] ?? null);
            }

            return $paths;
        }

        $paths = [];
        foreach (['cover', 'preview', 'icon'] as $slot) {
            $fileKey = $slot . '_image';
            $filename = self::normalizeFilename($storage[$fileKey] ?? null);
            $slotPaths = self::pathsForSlot($taxonomyType, $taxonomyId, $slot, $filename);
            foreach ($slotPaths as $key => $value) {
                $paths[$key] = $value;
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    public static function storageKeysForSlot(string $taxonomyType, string $slot): array
    {
        if (self::supportsFilenameStorage($taxonomyType)) {
            return [$slot . '_image'];
        }

        return self::imageKeysForSlot($slot);
    }

    /**
     * @return array<int, string>
     */
    public static function imageKeysForSlot(string $slot): array
    {
        if ($slot === 'cover') {
            return [
                'cover_image_path',
                'cover_image_sm_path',
                'cover_image_md_path',
                'cover_image_lg_path',
            ];
        }

        if ($slot === 'icon') {
            return [
                'icon_image_path',
                'icon_image_sm_path',
                'icon_image_md_path',
                'icon_image_lg_path',
            ];
        }

        return [
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ];
    }

    /**
     * @param array<string, string|null> $currentPaths
     * @param array<string, string|null> $nextPaths
     * @return array<int, string>
     */
    public static function removedPaths(array $currentPaths, array $nextPaths): array
    {
        $nextLookup = [];
        foreach ($nextPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized !== '') {
                $nextLookup[$normalized] = true;
            }
        }

        $removed = [];
        foreach ($currentPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized === '' || isset($nextLookup[$normalized])) {
                continue;
            }

            $removed[$normalized] = $normalized;
        }

        return array_values($removed);
    }

    /**
     * Returns true when the entity type stores source filenames instead of full paths.
     *
     * @param string $taxonomyType Entity type such as categories/channels/groups/tags.
     * @return bool
     */
    public static function supportsFilenameStorage(string $taxonomyType): bool
    {
        return in_array($taxonomyType, ['categories', 'groups', 'tags'], true);
    }

    /**
     * Resolves media max upload bytes from config for a target.
     *
     * @param string $target Logical target such as `images`.
     * @param int $defaultBytes Fallback byte limit when config is unset.
     * @return int Maximum allowed bytes, or `0` when unlimited.
     */
    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();

        if ($target === 'images') {
            $kb = (int) ($config['media']['max_filesize_kb'] ?? -1);
            if ($kb >= 0) {
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        }

        return max(1, $defaultBytes);
    }

    /**
     * Returns the storage path-key prefix for one image slot.
     *
     * @param string $slot Image slot name.
     * @return string Storage column key.
     */
    private static function pathKeyForSlot(string $slot): string
    {
        return $slot . '_image_path';
    }

    /**
     * @return array<string, string|null>
     */
    private static function pathsForSlot(string $taxonomyType, int $taxonomyId, string $slot, ?string $filename): array
    {
        $paths = [];
        $originalKey = self::pathKeyForSlot($slot);
        $paths[$originalKey] = null;
        foreach (['sm', 'md', 'lg'] as $variant) {
            $paths[$slot . '_image_' . $variant . '_path'] = null;
        }

        if ($filename === null || $taxonomyId < 1) {
            return $paths;
        }

        $relativeDirectory = 'uploads/' . $taxonomyType . '/' . $taxonomyId;
        $paths[$originalKey] = $relativeDirectory . '/' . $filename;
        foreach (['sm', 'md', 'lg'] as $variant) {
            $variantFilename = self::variantFilename($filename, $variant);
            $paths[$slot . '_image_' . $variant . '_path'] = $variantFilename !== null
                ? $relativeDirectory . '/' . $variantFilename
                : null;
        }

        return $paths;
    }

    /**
     * Builds one variant filename from source filename and variant key.
     *
     * @param string $filename Source filename.
     * @param string $variant Variant key such as `sm`, `md`, or `lg`.
     * @return string|null Variant filename, or null when source name is invalid.
     */
    private static function variantFilename(string $filename, string $variant): ?string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $basename = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($basename === '' || $extension === '') {
            return null;
        }

        return $basename . '_' . $variant . '.' . $extension;
    }

    /**
     * Normalizes one stored filename token.
     *
     * @param mixed $value Raw stored filename value.
     * @return string|null Safe basename, or null when invalid/empty.
     */
    private static function normalizeFilename(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || str_contains($raw, "\0")) {
            return null;
        }

        $filename = basename(str_replace('\\\\', '/', $raw));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        return $filename;
    }

    /**
     * Normalizes one stored path token to nullable string.
     *
     * @param mixed $value Raw stored path value.
     * @return string|null Normalized path, or null when empty.
     */
    private static function normalizePath(mixed $value): ?string
    {
        $path = trim((string) $value);
        return $path !== '' ? $path : null;
    }
}
