<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/DatabaseFactory.php
 * Database connection factory; wires driver config into profiled PDO connections.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime;

use PDO;
use Raven\Core\Debug\QueryProfilerPdo;
use Raven\Core\Debug\RequestProfilerAdapter;
use Raven\Lib\Database\DbDriver;
use Raven\Lib\Database\MysqlConfig;
use Raven\Lib\Database\PgsqlConfig;
use Raven\Core\Debug\QueryProfiler;
use Raven\Lib\Database\SqliteBootstrap;
use Raven\Lib\Database\SqliteConfig;

/**
 * Builds profiled PDO connections for Raven database backends.
 *
 * Translates the Raven database config array into concrete PDO instances,
 * applying driver-specific DSN construction, SQLite bootstrapping, and
 * query profiler wiring in one place.
 */
final class DatabaseFactory
{
    /** @var array<string, mixed> */
    private array $config;
    private QueryProfiler $queryProfiler;
    private DbDriver $dbDriver;
    private SqliteBootstrap $sqliteBootstrap;
    private ?SqliteConfig $sqlitePaths = null;

    /**
     * @param array<string, mixed> $databaseConfig Raven database configuration array (from config.php).
     * @param QueryProfiler|null $queryProfiler Optional profiler; defaults to RequestProfilerAdapter.
     */
    public function __construct(array $databaseConfig, ?QueryProfiler $queryProfiler = null)
    {
        $this->config = $databaseConfig;
        $this->queryProfiler = $queryProfiler ?? new RequestProfilerAdapter();
        $this->dbDriver = new DbDriver();
        $this->sqliteBootstrap = new SqliteBootstrap();
    }

    /**
     * Returns the normalized active DB driver name.
     *
     * @return string Canonical driver slug: `sqlite`, `mysql`, or `pgsql`.
     */
    public function getDriver(): string
    {
        return $this->dbDriver->driver($this->config);
    }

    /**
     * Returns the app table prefix used in mysql/pgsql mode.
     *
     * @return string Table prefix string, empty when not configured.
     */
    public function getPrefix(): string
    {
        return $this->dbDriver->prefix($this->config);
    }

    /**
     * Returns the app-data PDO connection.
     *
     * SQLite mode opens the consolidated core DB; server drivers connect to the
     * configured host with the `app` connection label for the query profiler.
     *
     * @return PDO Active app database connection.
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
     * Returns the auth PDO connection used by Delight Auth.
     *
     * SQLite mode shares the consolidated core DB file; server drivers connect
     * to the configured host with the `auth` connection label.
     *
     * @return PDO Active auth database connection.
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
     * Builds one SQLite PDO with secure defaults and WAL bootstrapping.
     *
     * @param string $path Absolute path to the SQLite database file.
     * @param string $connectionLabel Profiler label for this connection (`app` or `auth`).
     * @return PDO Configured SQLite connection.
     */
    private function newSqliteConnection(string $path, string $connectionLabel = 'app'): PDO
    {
        $this->sqliteBootstrap->ensureDir($path);

        $pdo = new QueryProfilerPdo('sqlite:' . $path, null, null, $this->defaultPdoOptions(), $connectionLabel, $this->queryProfiler);
        $this->sqliteBootstrap->bootstrap($pdo);

        return $pdo;
    }

    /**
     * Builds a MySQL or PostgreSQL PDO with strict error handling.
     *
     * @param string $driver Canonical driver slug: `mysql` or `pgsql`.
     * @param string $connectionLabel Profiler label for this connection.
     * @return PDO Configured server database connection.
     */
    private function newServerConnection(string $driver, string $connectionLabel = 'app'): PDO
    {
        if ($driver === 'mysql') {
            $mysql = MysqlConfig::fromConfig($this->config);

            return new QueryProfilerPdo(
                $mysql->dsn(),
                $mysql->username(),
                $mysql->password(),
                $this->defaultPdoOptions(),
                $connectionLabel,
                $this->queryProfiler
            );
        }

        $pgsql = PgsqlConfig::fromConfig($this->config);

        return new QueryProfilerPdo(
            $pgsql->dsn(),
            $pgsql->username(),
            $pgsql->password(),
            $this->defaultPdoOptions(),
            $connectionLabel,
            $this->queryProfiler
        );
    }

    /**
     * Resolves an SQLite file path by Raven canonical key.
     *
     * @param string $key Canonical path key (e.g., `core`).
     * @return string Absolute path to the SQLite database file.
     */
    private function sqlitePath(string $key): string
    {
        return $this->sqlitePaths()->path($key);
    }

    /**
     * Returns PDO options shared across all driver connections.
     *
     * @return array<int, mixed> PDO attribute map.
     */
    private function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }

    /**
     * Returns the lazy-initialized SQLite path resolver.
     *
     * @return SqliteConfig Resolver initialized from the database config base path.
     */
    private function sqlitePaths(): SqliteConfig
    {
        if ($this->sqlitePaths === null) {
            $this->sqlitePaths = SqliteConfig::fromConfig($this->config);
        }

        return $this->sqlitePaths;
    }
}
