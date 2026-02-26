<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Database/ConnectionFactory.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Isolate backend-specific database behavior behind one consistent API.

declare(strict_types=1);

namespace Raven\Core\Database;

use PDO;
use RuntimeException;

/**
 * Builds PDO connections for Raven backends.
 */
final class ConnectionFactory
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $databaseConfig
     */
    public function __construct(array $databaseConfig)
    {
        $this->config = $databaseConfig;
    }

    /**
     * Returns normalized active DB driver.
     */
    public function getDriver(): string
    {
        $driver = strtolower((string) ($this->config['driver'] ?? 'sqlite'));

        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        return $driver;
    }

    /**
     * Returns app table prefix used in mysql/pgsql mode.
     */
    public function getPrefix(): string
    {
        $prefix = (string) ($this->config['table_prefix'] ?? '');

        // Keep SQL identifier prefix strictly alphanumeric+underscore.
        return preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns the app-data connection.
     *
     * SQLite mode opens `pages.db` and attaches required side databases,
     * including extension datasets and security buckets.
     */
    public function createAppConnection(): PDO
    {
        $driver = $this->getDriver();

        if ($driver === 'sqlite') {
            $pdo = $this->newSqliteConnection($this->sqlitePath('pages'), 'app');
            $this->attachSqliteDatabases($pdo, [
                'groups',
                'taxonomy',
                'extensions',
                'login_failures',
            ]);
            return $pdo;
        }

        return $this->newServerConnection($driver);
    }

    /**
     * Returns auth connection used by Delight Auth.
     */
    public function createAuthConnection(): PDO
    {
        $driver = $this->getDriver();

        if ($driver === 'sqlite') {
            return $this->newSqliteConnection($this->sqlitePath('users'), 'auth');
        }

        return $this->newServerConnection($driver, 'auth');
    }

    /**
     * Builds one SQLite PDO with secure defaults.
     */
    private function newSqliteConnection(string $path, string $connectionLabel = 'app'): PDO
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $pdo = new ProfiledPDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ], $connectionLabel);

        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Builds MySQL or PostgreSQL PDO with strict error handling.
     */
    private function newServerConnection(string $driver, string $connectionLabel = 'app'): PDO
    {
        if ($driver === 'mysql') {
            /** @var array<string, mixed> $mysql */
            $mysql = (array) ($this->config['mysql'] ?? []);

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($mysql['host'] ?? '127.0.0.1'),
                (int) ($mysql['port'] ?? 3306),
                (string) ($mysql['dbname'] ?? 'raven'),
                (string) ($mysql['charset'] ?? 'utf8mb4')
            );

            return new ProfiledPDO(
                $dsn,
                (string) ($mysql['user'] ?? ''),
                (string) ($mysql['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
                $connectionLabel
            );
        }

        /** @var array<string, mixed> $pgsql */
        $pgsql = (array) ($this->config['pgsql'] ?? []);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($pgsql['host'] ?? '127.0.0.1'),
            (int) ($pgsql['port'] ?? 5432),
            (string) ($pgsql['dbname'] ?? 'raven')
        );

        return new ProfiledPDO(
            $dsn,
            (string) ($pgsql['user'] ?? ''),
            (string) ($pgsql['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
            $connectionLabel
        );
    }

    /**
     * Resolves SQLite file paths from Raven canonical key map.
     */
    private function sqlitePath(string $key): string
    {
        /** @var array<string, mixed> $sqlite */
        $sqlite = (array) ($this->config['sqlite'] ?? []);

        $basePath = rtrim((string) ($sqlite['base_path'] ?? ''), '/');
        $canonicalFiles = $this->sqliteCanonicalFiles();

        if ($basePath === '') {
            throw new RuntimeException("Missing SQLite path configuration for '{$key}'.");
        }

        if (isset($canonicalFiles[$key])) {
            return $basePath . '/' . $canonicalFiles[$key];
        }

        throw new RuntimeException("Missing SQLite path configuration for '{$key}'.");
    }

    /**
     * Returns canonical SQLite filenames used by Raven.
     *
     * @return array<string, string>
     */
    private function sqliteCanonicalFiles(): array
    {
        return [
            'pages' => 'pages.db',
            'users' => 'users.db',
            'groups' => 'groups.db',
            'taxonomy' => 'taxonomy.db',
            'extensions' => 'extensions.db',
            'login_failures' => 'login_failures.db',
        ];
    }

    /**
     * Attaches side SQLite files as named schemas.
     *
     * @param array<int, string> $aliases
     */
    private function attachSqliteDatabases(PDO $pdo, array $aliases): void
    {
        foreach ($aliases as $alias) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $alias)) {
                continue;
            }

            $path = $this->sqlitePath($alias);
            $safePath = str_replace("'", "''", $path);

            $pdo->exec("ATTACH DATABASE '{$safePath}' AS {$alias}");
        }
    }
}
