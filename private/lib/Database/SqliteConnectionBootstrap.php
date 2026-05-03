<?php

declare(strict_types=1);

namespace Raven\Lib\Database;

use PDO;
use RuntimeException;

/**
 * Applies SQLite filesystem and pragma initialization steps.
 */
final class SqliteConnectionBootstrap
{
    public function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create SQLite data directory: ' . $directory);
        }
    }

    public function bootstrap(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

