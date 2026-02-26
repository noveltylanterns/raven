<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Theme/PublicThemeRegistry.php
 * Discovers and validates public theme manifests.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Theme;

/**
 * Enumerates public themes from `public/theme/{slug}/theme.json`.
 */
final class PublicThemeRegistry
{
    /**
     * Returns discovered theme manifests keyed by slug.
     *
     * @return array<string, array{
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * }>
     */
    public static function manifests(string $themesRoot): array
    {
        if (!is_dir($themesRoot)) {
            return [];
        }

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
            if (!self::isValidSlug($slug)) {
                continue;
            }

            $themeDirectory = rtrim($themesRoot, '/\\') . DIRECTORY_SEPARATOR . $slug;
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

            $name = trim((string) ($decodedManifest['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $isChildTheme = self::toBool($decodedManifest['is_child_theme'] ?? false);
            $parentTheme = strtolower(trim((string) ($decodedManifest['parent_theme'] ?? '')));
            if (!$isChildTheme) {
                $parentTheme = '';
            }

            if ($parentTheme !== '' && !self::isValidSlug($parentTheme)) {
                $parentTheme = '';
            }

            if ($parentTheme === $slug) {
                $parentTheme = '';
            }

            $manifests[$slug] = [
                'name' => $name,
                'is_child_theme' => $isChildTheme && $parentTheme !== '',
                'parent_theme' => $parentTheme,
            ];
        }

        if ($manifests === []) {
            return [];
        }

        // Keep order deterministic by human-facing theme name.
        uasort($manifests, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $manifests;
    }

    /**
     * Returns discovered public themes as slug => display name.
     *
     * @return array<string, string>
     */
    public static function options(string $themesRoot): array
    {
        $options = [];
        foreach (self::manifests($themesRoot) as $slug => $manifest) {
            $options[$slug] = (string) ($manifest['name'] ?? '');
        }

        return $options;
    }

    /**
     * Resolves one public theme inheritance chain from child to topmost parent.
     *
     * @return array<int, string>
     */
    public static function inheritanceChain(string $themesRoot, string $themeSlug): array
    {
        $themeSlug = strtolower(trim($themeSlug));
        if (!self::isValidSlug($themeSlug)) {
            return [];
        }

        $manifests = self::manifests($themesRoot);
        if (!isset($manifests[$themeSlug])) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $themeSlug;
        $maxDepth = 12;

        for ($i = 0; $i < $maxDepth; $i++) {
            if (isset($visited[$current]) || !isset($manifests[$current])) {
                break;
            }

            $visited[$current] = true;
            $chain[] = $current;

            $manifest = $manifests[$current];
            $isChildTheme = (bool) ($manifest['is_child_theme'] ?? false);
            $parentTheme = (string) ($manifest['parent_theme'] ?? '');
            if (!$isChildTheme || $parentTheme === '' || !isset($manifests[$parentTheme])) {
                break;
            }

            $current = $parentTheme;
        }

        return $chain;
    }

    /**
     * Returns true when one theme folder slug is safe for routing and file lookup.
     */
    private static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
    }

    /**
     * Normalizes mixed boolean-like manifest values.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
