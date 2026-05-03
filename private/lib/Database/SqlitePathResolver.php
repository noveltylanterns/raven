<?php

declare(strict_types=1);

namespace Raven\Lib\Database;

use RuntimeException;

/**
 * Resolves canonical SQLite DB file paths from runtime configuration.
 */
final class SqlitePathResolver
{
    private string $configuredPath;

    public function __construct(string $basePath)
    {
        $this->configuredPath = rtrim($basePath, '/');
    }

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
