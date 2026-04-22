<?php

/**
 * RAVEN CMS
 * ~/private/sys/Database/ConnectionFactory.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

use PDO;
use Raven\Core\Database\Connection\DriverConfigNormalizer;
use Raven\Core\Database\Connection\DsnBuilder;
use Raven\Core\Database\Connection\SqliteConnectionBootstrap;
use Raven\Core\Database\Connection\SqlitePathResolver;
use Raven\Core\Debug\RequestQueryProfilerAdapter;
use Raven\Lib\Database\ProfiledPDO;
use Raven\Lib\Database\QueryProfilerInterface;

/**
 * Builds PDO connections for Raven backends.
 */
final class ConnectionFactory
{
    /** @var array<string, mixed> */
    private array $config;
    private QueryProfilerInterface $queryProfiler;
    private DriverConfigNormalizer $configNormalizer;
    private DsnBuilder $dsnBuilder;
    private SqliteConnectionBootstrap $sqliteBootstrap;
    private ?SqlitePathResolver $sqlitePaths = null;

    /**
     * @param array<string, mixed> $databaseConfig
     */
    public function __construct(array $databaseConfig, ?QueryProfilerInterface $queryProfiler = null)
    {
        $this->config = $databaseConfig;
        $this->queryProfiler = $queryProfiler ?? new RequestQueryProfilerAdapter();
        $this->configNormalizer = new DriverConfigNormalizer();
        $this->dsnBuilder = new DsnBuilder();
        $this->sqliteBootstrap = new SqliteConnectionBootstrap();
    }

    /**
     * Returns normalized active DB driver.
     */
    public function getDriver(): string
    {
        return $this->configNormalizer->driver($this->config);
    }

    /**
     * Returns app table prefix used in mysql/pgsql mode.
     */
    public function getPrefix(): string
    {
        return $this->configNormalizer->prefix($this->config);
    }

    /**
     * Returns the app-data connection.
     *
     * SQLite mode opens the consolidated core DB.
     */
    public function createAppConnection(): PDO
    {
        $driver = $this->getDriver();

        if ($driver === 'sqlite') {
            return $this->newSqliteConnection($this->sqlitePath('core'), 'app');
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
            return $this->newSqliteConnection($this->sqlitePath('core'), 'auth');
        }

        return $this->newServerConnection($driver, 'auth');
    }

    /**
     * Builds one SQLite PDO with secure defaults.
     */
    private function newSqliteConnection(string $path, string $connectionLabel = 'app'): PDO
    {
        $this->sqliteBootstrap->ensureDirectory($path);

        $pdo = new ProfiledPDO('sqlite:' . $path, null, null, $this->defaultPdoOptions(), $connectionLabel, $this->queryProfiler);
        $this->sqliteBootstrap->bootstrap($pdo);

        return $pdo;
    }

    /**
     * Builds MySQL or PostgreSQL PDO with strict error handling.
     */
    private function newServerConnection(string $driver, string $connectionLabel = 'app'): PDO
    {
        if ($driver === 'mysql') {
            $mysql = $this->configNormalizer->mysql($this->config);

            return new ProfiledPDO(
                $this->dsnBuilder->mysql($mysql),
                (string) ($mysql['user'] ?? ''),
                (string) ($mysql['pass'] ?? ''),
                $this->defaultPdoOptions(),
                $connectionLabel,
                $this->queryProfiler
            );
        }

        $pgsql = $this->configNormalizer->pgsql($this->config);

        return new ProfiledPDO(
            $this->dsnBuilder->pgsql($pgsql),
            (string) ($pgsql['user'] ?? ''),
            (string) ($pgsql['pass'] ?? ''),
            $this->defaultPdoOptions(),
            $connectionLabel,
            $this->queryProfiler
        );
    }

    /**
     * Resolves SQLite file paths from Raven canonical key map.
     */
    private function sqlitePath(string $key): string
    {
        return $this->sqlitePaths()->path($key);
    }

    /**
     * @return array<int, mixed>
     */
    private function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }

    private function sqlitePaths(): SqlitePathResolver
    {
        if ($this->sqlitePaths === null) {
            $basePath = $this->configNormalizer->sqliteBasePath($this->config);
            $this->sqlitePaths = new SqlitePathResolver($basePath);
        }

        return $this->sqlitePaths;
    }
}
