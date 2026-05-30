<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/ThemeDiscovery.php
 * Public-theme manifest discovery and inheritance-chain primitives.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Enumerates and resolves installed public themes from `public/theme/{slug}/theme.json`.
 */
final class ThemeDiscovery
{
    private static ?ThemeValidator $validator = null;

    /**
     * Returns discovered theme manifests keyed by slug.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @return array<string, array{name: string, is_child_theme: bool, parent_theme: string}> Valid manifests keyed by slug.
     */
    public static function manifests(string $themesRoot): array
    {
        // Missing theme root means no discoverable manifests.
        if (!is_dir($themesRoot)) {
            return [];
        }

        $themesRoot = rtrim($themesRoot, '/\\');
        $directoryEntries = scandir($themesRoot);
        // Abort discovery when root scan fails.
        if (!is_array($directoryEntries)) {
            return [];
        }

        $validator = self::validator();
        $manifests = [];
        // Validate each root entry as a potential theme directory.
        foreach ($directoryEntries as $entry) {
            // Skip dot entries from scandir output.
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $slug = strtolower(trim($entry));
            // Enforce slug policy before touching per-theme files.
            if (!$validator->isValidSlug($slug)) {
                continue;
            }

            $themeDirectory = $themesRoot . DIRECTORY_SEPARATOR . $slug;
            // Ignore entries that are not directories.
            if (!is_dir($themeDirectory)) {
                continue;
            }

            $manifestPath = $themeDirectory . DIRECTORY_SEPARATOR . 'theme.json';
            // Manifest file must exist and be readable.
            if (!is_file($manifestPath) || !is_readable($manifestPath)) {
                continue;
            }

            $rawManifest = file_get_contents($manifestPath);
            // Skip unreadable or empty manifest payloads.
            if (!is_string($rawManifest) || trim($rawManifest) === '') {
                continue;
            }

            /** @var mixed $decodedManifest */
            $decodedManifest = json_decode($rawManifest, true);
            // Skip invalid JSON documents.
            if (!is_array($decodedManifest)) {
                continue;
            }

            $normalized = $validator->normalize($slug, $decodedManifest);
            // Skip manifests that fail schema normalization.
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

    /**
     * Returns discovered public themes as slug => display name.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @return array<string, string> Installed theme option map.
     */
    public static function options(string $themesRoot): array
    {
        $options = [];
        // Map discovered manifests to slug => display-name option pairs.
        foreach (self::manifests($themesRoot) as $slug => $manifest) {
            $options[$slug] = (string) ($manifest['name'] ?? '');
        }

        return $options;
    }

    /**
     * Resolves one public theme inheritance chain from child to topmost parent.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $themeSlug Theme slug to resolve from.
     * @return array<int, string> Child-first inheritance chain.
     */
    public static function inheritanceChain(string $themesRoot, string $themeSlug): array
    {
        $manifests = self::manifests($themesRoot);
        $themeSlug = strtolower(trim($themeSlug));
        // Unknown/blank theme slugs cannot produce an inheritance chain.
        if ($themeSlug === '' || !isset($manifests[$themeSlug])) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $themeSlug;
        $maxDepth = 12;

        // Walk parent links with cycle and depth protection.
        for ($index = 0; $index < $maxDepth; $index++) {
            // Stop on cycles or missing manifests.
            if (isset($visited[$current]) || !isset($manifests[$current])) {
                break;
            }

            $visited[$current] = true;
            $chain[] = $current;

            $manifest = $manifests[$current];
            $isChildTheme = (bool) ($manifest['is_child_theme'] ?? false);
            $parentTheme = (string) ($manifest['parent_theme'] ?? '');
            // Stop when chain reaches non-child or invalid parent declaration.
            if (!$isChildTheme || $parentTheme === '' || !isset($manifests[$parentTheme])) {
                break;
            }

            $current = $parentTheme;
        }

        return $chain;
    }

    /**
     * Returns the shared public-theme manifest validator.
     *
     * @return ThemeValidator Shared public-theme manifest validator.
     */
    private static function validator(): ThemeValidator
    {
        // Lazily instantiate shared validator once per process.
        if (!self::$validator instanceof ThemeValidator) {
            self::$validator = new ThemeValidator();
        }

        return self::$validator;
    }
}
