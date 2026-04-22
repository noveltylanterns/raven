<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Theme.php
 * Shared public-theme discovery, option, and inheritance helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\View\Public\ThemeValidator;

/**
 * Enumerates and resolves installed public themes from `public/theme/{slug}/theme.json`.
 */
final class Theme
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
        if (!is_dir($themesRoot)) {
            return [];
        }

        $themesRoot = rtrim($themesRoot, '/\\');
        $directoryEntries = scandir($themesRoot);
        if (!is_array($directoryEntries)) {
            return [];
        }

        $validator = self::validator();
        $manifests = [];
        foreach ($directoryEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $slug = strtolower(trim($entry));
            if (!$validator->isValidSlug($slug)) {
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

            $normalized = $validator->normalize($slug, $decodedManifest);
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
        if ($themeSlug === '' || !isset($manifests[$themeSlug])) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $themeSlug;
        $maxDepth = 12;

        for ($index = 0; $index < $maxDepth; $index++) {
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
     * Returns the shared public-theme manifest validator.
     *
     * @return ThemeValidator Shared public-theme manifest validator.
     */
    private static function validator(): ThemeValidator
    {
        if (!self::$validator instanceof ThemeValidator) {
            self::$validator = new ThemeValidator();
        }

        return self::$validator;
    }
}
