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
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            return false;
        }

        $current = '';
        foreach (explode('/', trim($path, '/')) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            // Resolve dot-dot segments before checking the next physical node.
            if ($component === '..') {
                $current = dirname($current === '' ? '/' : $current);
                continue;
            }

            $current .= '/' . $component;
            if (is_link($current)) {
                return false;
            }
        }

        return true;
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
