<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/SqlitePathResolver.php
 * Resolves canonical SQLite DB file paths from runtime configuration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

use RuntimeException;

/**
 * Resolves canonical SQLite DB file paths from runtime configuration.
 */
final class SqlitePathResolver
{
    private string $configuredPath;

    /**
     * Stores the configured SQLite base path, trimming any trailing slash.
     *
     * @param string $basePath Configured SQLite path value from the database config (file or directory).
     */
    public function __construct(string $basePath)
    {
        $this->configuredPath = rtrim($basePath, '/');
    }

    /**
     * Returns the absolute SQLite file path for the given Raven canonical key.
     *
     * All current keys ('core', 'pages', 'auth', 'taxonomy') resolve to the same
     * consolidated database file. An unknown key throws rather than silently returning
     * a wrong path, so misconfigured callers fail fast.
     *
     * @param string $key Canonical path key: 'core', 'pages', 'auth', or 'taxonomy'.
     * @return string Absolute path to the SQLite database file.
     * @throws RuntimeException When the key is not a recognized Raven path key.
     */
    public function path(string $key): string
    {
        return match ($key) {
            'core', 'pages', 'auth', 'taxonomy' => $this->corePath(),
            default => throw new RuntimeException("Missing SQLite path configuration for '{$key}'."),
        };
    }

    private function corePath(): string
    {
        if ($this->looksLikeFilePath($this->configuredPath)) {
            return $this->configuredPath;
        }

        $directory = $this->configuredPath;
        if (basename($directory) === 'db') {
            return dirname($directory) . '/db.sqlite';
        }

        return $directory . '/db.sqlite';
    }

    private function looksLikeFilePath(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['db', 'sqlite'], true);
    }
}
