<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Discovers and validates public theme manifests from one themes root.
 */
final class ThemeDiscoveryService
{
    private ThemeManifestValidator $validator;

    public function __construct(?ThemeManifestValidator $validator = null)
    {
        $this->validator = $validator ?? new ThemeManifestValidator();
    }

    /**
     * @return array<string, array{name: string, is_child_theme: bool, parent_theme: string}>
     */
    public function manifests(string $themesRoot): array
    {
        if (!is_dir($themesRoot)) {
            return [];
        }
        $themesRoot = rtrim($themesRoot, '/\\');

        $directoryEntries = scandir($themesRoot);
        if (!is_array($directoryEntries)) {
            return [];
        }

        $manifests = [];
        foreach ($directoryEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $slug = strtolower(trim($entry));
            if (!$this->validator->isValidSlug($slug)) {
                continue;
            }

            $themeDirectory = $themesRoot . DIRECTORY_SEPARATOR . $slug;
            if (!is_dir($themeDirectory)) {
                continue;
            }

            $manifestPath = $themeDirectory . DIRECTORY_SEPARATOR . 'theme.json';
            if (!is_file($manifestPath) || !is_readable($manifestPath)) {
                continue;
            }

            $rawManifest = file_get_contents($manifestPath);
            if (!is_string($rawManifest) || trim($rawManifest) === '') {
                continue;
            }

            /** @var mixed $decodedManifest */
            $decodedManifest = json_decode($rawManifest, true);
            if (!is_array($decodedManifest)) {
                continue;
            }

            $normalized = $this->validator->normalize($slug, $decodedManifest);
            if (!is_array($normalized)) {
                continue;
            }

            $manifests[$slug] = $normalized;
        }

        uasort($manifests, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $manifests;
    }
}
