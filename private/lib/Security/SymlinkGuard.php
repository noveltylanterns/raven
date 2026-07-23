<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/SymlinkGuard.php
 * Shared filesystem symlink-boundary validation.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use RuntimeException;

/**
 * Provides one canonical policy for paths that must remain physically local.
 */
final class SymlinkGuard
{
    /**
     * Returns whether an absolute path contains no symbolic-link component.
     *
     * Existing parent components are checked even when the final path entry does
     * not exist yet, allowing callers to validate safe creation destinations.
     *
     * @param string $path Absolute path to inspect.
     * @return bool True when no path component resolves through a symbolic link.
     */
    public static function isSymlinkFreePath(string $path): bool
    {
        $path = self::normalizeAbsolutePath($path);
        if ($path === null) {
            return false;
        }

        $openBaseDirectories = self::openBaseDirectories();
        // Reject paths outside the PHP allowlist before probing any filesystem component.
        if ($openBaseDirectories !== [] && !self::pathWithinDirectories($path, $openBaseDirectories)) {
            return false;
        }

        $current = '';
        foreach (explode('/', trim($path, '/')) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            $current .= '/' . $component;
            // PHP cannot inspect ancestors outside open_basedir; wait until the walk enters an allowed root.
            if ($openBaseDirectories !== [] && !self::pathWithinDirectories($current, $openBaseDirectories)) {
                continue;
            }

            if (@is_link($current)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalizes one Unix-style absolute path without touching the filesystem.
     *
     * @param string $path Candidate absolute path.
     * @return string|null Normalized absolute path, or null for relative paths.
     */
    private static function normalizeAbsolutePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        $components = [];
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            if ($component === '..') {
                array_pop($components);
                continue;
            }

            $components[] = $component;
        }

        return '/' . implode('/', $components);
    }

    /**
     * Returns normalized open_basedir roots without resolving them through the filesystem.
     *
     * @return array<int, string> Absolute allowlist roots, or an empty array when unrestricted.
     */
    private static function openBaseDirectories(): array
    {
        $rawOpenBaseDirectories = trim((string) ini_get('open_basedir'));
        if ($rawOpenBaseDirectories === '') {
            return [];
        }

        $directories = [];
        foreach (explode(PATH_SEPARATOR, $rawOpenBaseDirectories) as $directory) {
            $normalized = self::normalizeAbsolutePath($directory);
            if ($normalized !== null) {
                $directories[$normalized] = $normalized;
            }
        }

        return array_values($directories);
    }

    /**
     * Returns whether a path is inside one of the supplied directory roots.
     *
     * @param string $path Normalized absolute path to test.
     * @param array<int, string> $directories Normalized absolute allowlist roots.
     * @return bool True when the path is a root or descendant of an allowlisted directory.
     */
    private static function pathWithinDirectories(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if ($path === $directory || str_starts_with($path, rtrim($directory, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rejects a path whose existing components include a symbolic link.
     *
     * @param string $path Absolute path to inspect.
     * @param string $label Human-readable path role for the exception message.
     * @return void
     * @throws RuntimeException When the path is relative or contains a symlink.
     */
    public static function assertSymlinkFreePath(string $path, string $label = 'Path'): void
    {
        if (!self::isSymlinkFreePath($path)) {
            throw new RuntimeException($label . ' cannot contain a symbolic link: ' . $path);
        }
    }
}
