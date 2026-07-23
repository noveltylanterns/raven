<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Resolver.php
 * Canonical extension filesystem layout resolver.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Resolves canonical extension file locations.
 */
final class Resolver
{
    /**
     * Returns whether an absolute path contains no symbolic-link component.
     *
     * The path may end in a not-yet-created entry; every existing parent component
     * is still checked so callers can safely create a bucket at the intended location.
     *
     * @param string $path Absolute path to inspect.
     * @return bool True when no component resolves through a symbolic link.
     */
    public static function isSymlinkFreePath(string $path): bool
    {
        return $path !== '' && str_starts_with(str_replace('\\', '/', $path), '/')
            && !self::hasSymlinkComponent($path);
    }

    /**
     * Returns whether an extension root and its descendants are free of symlink components.
     *
     * Executable extension files must remain physically inside the installed extension tree;
     * rejecting every symlink component prevents a provider or class directory from escaping
     * that boundary through either a direct link or a linked parent directory.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @return bool True when the existing extension root contains no symlink component.
     */
    public static function isSafeExtensionRoot(string $extensionRoot): bool
    {
        $extensionRoot = rtrim(str_replace('\\', '/', $extensionRoot), '/');
        if ($extensionRoot === '' || self::hasSymlinkComponent($extensionRoot)) {
            return false;
        }

        return is_dir($extensionRoot) && realpath($extensionRoot) !== false;
    }

    /**
     * Returns an existing extension-local file only when it contains no symlink component.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $filename      Extension-local filename, never a nested path.
     * @return string|null Resolved local file path, or null when absent or unsafe.
     */
    public static function safeFilePath(string $extensionRoot, string $filename): ?string
    {
        if (
            $filename === ''
            || basename($filename) !== $filename
            || str_contains($filename, '\\')
            || !self::isSafeExtensionRoot($extensionRoot)
        ) {
            return null;
        }

        $extensionRoot = rtrim(str_replace('\\', '/', $extensionRoot), '/');
        $candidate = $extensionRoot . '/' . $filename;
        if (!is_file($candidate) || self::hasSymlinkComponent($candidate)) {
            return null;
        }

        $resolvedRoot = realpath($extensionRoot);
        $resolvedCandidate = realpath($candidate);
        if ($resolvedRoot === false || $resolvedCandidate === false) {
            return null;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $resolvedCandidate), $rootPrefix)) {
            return null;
        }

        return $resolvedCandidate;
    }

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
        return self::safeFilePath($extensionRoot, $filename);
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
        if (!self::isSafeExtensionRoot($extensionRoot)) {
            return [];
        }

        $resolvedRoot = realpath($extensionRoot);
        if ($resolvedRoot === false) {
            return [];
        }

        $classRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/lib';
        // A linked lib directory would let the autoloader execute outside the extension tree.
        if (self::hasSymlinkComponent($classRoot)) {
            return [];
        }

        return [
            $classRoot,
        ];
    }

    /**
     * Returns whether any component of an absolute path is a symbolic link.
     *
     * This small bootstrap-local copy remains here because Resolver is loaded
     * before Raven registers its general PSR-4 autoloader during early extension
     * discovery. Archive and compression code uses Security\\SymlinkGuard lazily.
     *
     * @param string $path Absolute path to inspect.
     * @return bool True when one path component is a symbolic link.
     */
    private static function hasSymlinkComponent(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            return true;
        }

        $current = '';
        foreach (explode('/', trim($path, '/')) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            if ($component === '..') {
                $current = dirname($current === '' ? '/' : $current);
                continue;
            }

            $current .= '/' . $component;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }
}
