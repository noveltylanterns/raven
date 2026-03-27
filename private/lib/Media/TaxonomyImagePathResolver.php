<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Resolves taxonomy image storage payloads into deterministic public paths.
 */
final class TaxonomyImagePathResolver
{
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

        return [
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ];
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

    public static function supportsFilenameStorage(string $taxonomyType): bool
    {
        return in_array($taxonomyType, ['categories', 'tags'], true);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public static function storagePayloadFromRecord(string $taxonomyType, ?array $record): array
    {
        if (self::supportsFilenameStorage($taxonomyType)) {
            return [
                'cover_image' => self::normalizeFilename($record['cover_image'] ?? $record['cover_image_file'] ?? null),
                'preview_image' => self::normalizeFilename($record['preview_image'] ?? $record['preview_image_file'] ?? null),
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
        foreach (['cover', 'preview'] as $slot) {
            $fileKey = $slot . '_image';
            $filename = self::normalizeFilename($storage[$fileKey] ?? null);
            $slotPaths = self::pathsForSlot($taxonomyType, $taxonomyId, $slot, $filename);
            foreach ($slotPaths as $key => $value) {
                $paths[$key] = $value;
            }
        }

        return $paths;
    }

    public static function pathKeyForSlot(string $slot): string
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

    private static function variantFilename(string $filename, string $variant): ?string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $basename = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($basename === '' || $extension === '') {
            return null;
        }

        return $basename . '_' . $variant . '.' . $extension;
    }

    private static function normalizeFilename(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || str_contains($raw, "\0")) {
            return null;
        }

        $filename = basename(str_replace('\\', '/', $raw));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        return $filename;
    }

    private static function normalizePath(mixed $value): ?string
    {
        $path = trim((string) $value);
        return $path !== '' ? $path : null;
    }
}
