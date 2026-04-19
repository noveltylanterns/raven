<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Layout.php
 * Canonical extension filesystem layout resolver with legacy fallbacks.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Resolves canonical extension file locations while tolerating legacy layouts.
 */
final class Layout
{
    /**
     * Returns the canonical provider path for one extension-local root file.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $filename Provider basename such as `routes_panel.php`.
     * @return string Absolute canonical path.
     */
    public static function canonicalProviderPath(string $extensionRoot, string $filename): string
    {
        return rtrim($extensionRoot, '/\\') . '/' . ltrim($filename, '/\\');
    }

    /**
     * Returns the legacy provider path for one extension-local provider file.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $filename Provider basename such as `routes_panel.php`.
     * @return string Absolute legacy path under `lib/`.
     */
    public static function legacyProviderPath(string $extensionRoot, string $filename): string
    {
        return rtrim($extensionRoot, '/\\') . '/lib/' . ltrim($filename, '/\\');
    }

    /**
     * Returns the best available provider path, preferring the canonical layout.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $filename Provider basename such as `schema.php`.
     * @return string|null Existing provider path, or null when neither layout exists.
     */
    public static function providerPath(string $extensionRoot, string $filename): ?string
    {
        $canonicalPath = self::canonicalProviderPath($extensionRoot, $filename);
        if (is_file($canonicalPath)) {
            return $canonicalPath;
        }

        $legacyPath = self::legacyProviderPath($extensionRoot, $filename);
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    /**
     * Returns whether one provider exists in either the canonical or legacy layout.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $filename Provider basename such as `shortcodes.php`.
     * @return bool True when a matching file exists.
     */
    public static function hasProvider(string $extensionRoot, string $filename): bool
    {
        return self::providerPath($extensionRoot, $filename) !== null;
    }

    /**
     * Returns extension class roots ordered by canonical preference.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @return array<int, string> Ordered class roots (`lib/` first, `src/` fallback second).
     */
    public static function classRoots(string $extensionRoot): array
    {
        return [
            rtrim($extensionRoot, '/\\') . '/lib',
            rtrim($extensionRoot, '/\\') . '/src',
        ];
    }
}
