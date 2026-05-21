<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/SqliteBootstrap.php
 * SQLite filesystem and pragma initialization helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

use PDO;
use RuntimeException;

/**
 * Applies SQLite filesystem and pragma initialization steps.
 */
final class SqliteBootstrap
{
    /**
     * Applies mandatory SQLite PRAGMAs to a freshly opened connection.
     *
     * @param PDO $pdo SQLite PDO connection to initialize.
     */
    public function bootstrap(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Creates the parent directory of an SQLite database file when it does not exist.
     *
     * @param string $path Absolute path to the SQLite database file (not the directory itself).
     * @throws RuntimeException When the directory cannot be created.
     */
    public function ensureDir(string $path): void
    {
        $directory = dirname($path);
        // No-op when the target parent directory already exists.
        if (is_dir($directory)) {
            return;
        }

        // Create directory recursively; verify result to avoid silent mkdir failures.
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create SQLite data directory: ' . $directory);
        }
    }
}
