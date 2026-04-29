<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Layout.php
 * Canonical extension filesystem layout resolver.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Resolves canonical extension file locations.
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
     * Returns the canonical provider path when it exists.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $filename Provider basename such as `schema.php`.
     * @return string|null Existing provider path, or null when the provider is absent.
     */
    public static function providerPath(string $extensionRoot, string $filename): ?string
    {
        $canonicalPath = self::canonicalProviderPath($extensionRoot, $filename);
        if (is_file($canonicalPath)) {
            return $canonicalPath;
        }

        return null;
    }

    /**
     * Returns whether one provider exists in the canonical layout.
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
     * Returns the canonical extension class root.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @return array<int, string> Ordered class roots for `Raven\Ext\*` autoloading.
     */
    public static function classRoots(string $extensionRoot): array
    {
        return [
            rtrim($extensionRoot, '/\\') . '/lib',
        ];
    }
}
