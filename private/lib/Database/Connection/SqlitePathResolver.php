<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Connection;

use RuntimeException;

/**
 * Resolves canonical SQLite DB file paths from runtime configuration.
 */
final class SqlitePathResolver
{
    private string $basePath;

    /** @var array<string, string> */
    private array $canonicalFiles = [
        'pages' => 'pages.db',
        'auth' => 'auth.db',
        'taxonomy' => 'taxonomy.db',
        'extensions' => 'extensions.db',
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function path(string $key): string
    {
        if (!isset($this->canonicalFiles[$key])) {
            throw new RuntimeException("Missing SQLite path configuration for '{$key}'.");
        }

        return $this->basePath . '/' . $this->canonicalFiles[$key];
    }
}

