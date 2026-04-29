<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/Panel/TaxonomyImageService.php
 * Read-side config and path helper for taxonomy/channel/group image editors.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media\Panel;

use Raven\Core\Config;
use Raven\Lib\Media\TaxonomyImagePathResolver;

/**
 * Shared read-side helper for taxonomy image config and path resolution.
 */
final class TaxonomyImageService
{
    private Config $config;
    private ImageVariantProcessor $variantProcessor;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->variantProcessor = new ImageVariantProcessor($config);
    }

    /**
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

    public function allowedImageExtensionsLabel(): string
    {
        $allowed = $this->allowedImageExtensions();
        return $allowed === [] ? 'none (uploads disabled)' : implode(', ', $allowed);
    }

    public function maxImageFilesizeKb(): ?int
    {
        $bytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        if ($bytes <= 0) {
            return null;
        }

        return (int) max(1, ceil($bytes / 1024));
    }

    /**
     * @return array<string, array{width: int, height: int}>
     */
    public function imageVariantSpecs(): array
    {
        return $this->variantProcessor->variantSpecs();
    }

    /**
     * @return array<string, string|null>
     */
    public function imagePathsFromRecord(string $taxonomyType, int $taxonomyId, ?array $record): array
    {
        return TaxonomyImagePathResolver::pathsFromRecord($taxonomyType, $taxonomyId, $record);
    }

    /**
     * @return array<int, string>
     */
    public function imageStorageKeysForSlot(string $taxonomyType, string $slot): array
    {
        return TaxonomyImagePathResolver::storageKeysForSlot($taxonomyType, $slot);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public function imageStoragePayloadFromRecord(string $taxonomyType, ?array $record): array
    {
        return TaxonomyImagePathResolver::storagePayloadFromRecord($taxonomyType, $record);
    }

    /**
     * @param array<string, mixed> $storage
     * @return array<string, string|null>
     */
    public function imagePathsFromStoragePayload(string $taxonomyType, int $taxonomyId, array $storage): array
    {
        return TaxonomyImagePathResolver::pathsFromStoragePayload($taxonomyType, $taxonomyId, $storage);
    }

    /**
     * @param array<string, string|null> $currentPaths
     * @param array<string, string|null> $nextPaths
     * @return array<int, string>
     */
    public function removedPaths(array $currentPaths, array $nextPaths): array
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

    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();

        if ($target === 'images') {
            // New flat path: media.max_filesize_kb
            $kb = (int) ($config['media']['max_filesize_kb'] ?? -1);
            if ($kb >= 0) {
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        }

        return max(1, $defaultBytes);
    }
}
