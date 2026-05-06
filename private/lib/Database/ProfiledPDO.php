<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/ProfiledPDO.php
 * PDO subclass that records every query through an optional QueryProfilerInterface.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO subclass that feeds every query into the active query profiler when enabled.
 */
final class ProfiledPDO extends PDO
{
    private string $connectionLabel;
    private ?QueryProfilerInterface $queryProfiler;

    /**
     * Opens a PDO connection and wires all prepared statements through the profiler.
     *
     * @param string                      $dsn             PDO DSN string.
     * @param string|null                 $username        Database username (null for SQLite).
     * @param string|null                 $password        Database password (null for SQLite).
     * @param array<int|string, mixed>    $options         PDO driver options.
     * @param string                      $connectionLabel Profiler label identifying this connection (e.g., 'app', 'auth').
     * @param QueryProfilerInterface|null $queryProfiler   Profiler instance; null disables query recording.
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        string $connectionLabel = 'app',
        ?QueryProfilerInterface $queryProfiler = null
    ) {
        $this->connectionLabel = strtolower(trim($connectionLabel)) !== '' ? strtolower(trim($connectionLabel)) : 'app';
        $this->queryProfiler = $queryProfiler;
        parent::__construct($dsn, $username, $password, $options);

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            ProfiledPDOStatement::class,
            [$this->connectionLabel, $this->queryProfiler],
        ]);
    }

    /**
     * Prepares a statement, injecting the profiled statement class when not overridden by the caller.
     *
     * @param string               $query   SQL query string to prepare.
     * @param array<int|string, mixed> $options PDO driver options for the statement.
     * @return PDOStatement|false Prepared statement, or false on failure.
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (!isset($options[PDO::ATTR_STATEMENT_CLASS])) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [
                ProfiledPDOStatement::class,
                [$this->connectionLabel, $this->queryProfiler],
            ];
        }

        return parent::prepare($query, $options);
    }

    /**
     * Executes a raw SQL statement and records it in the profiler.
     *
     * @param string $statement SQL statement to execute directly.
     * @return int|false Number of affected rows, or false on failure.
     */
    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);
        try {
            $result = parent::exec($statement);
            $this->record('exec', $statement, null, (microtime(true) - $startedAt) * 1000, $result !== false, null);
            return $result;
        } catch (Throwable $exception) {
            $this->record('exec', $statement, null, (microtime(true) - $startedAt) * 1000, false, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Executes a query and records it in the profiler.
     *
     * @param string   $query        SQL query to execute.
     * @param int|null $fetchMode    Optional PDO fetch mode constant.
     * @param mixed    ...$fetchModeArgs Additional fetch-mode arguments forwarded to the parent.
     * @return PDOStatement|false Result statement, or false on failure.
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $startedAt = microtime(true);
        try {
            if ($fetchMode === null) {
                $result = parent::query($query);
            } elseif ($fetchModeArgs === []) {
                $result = parent::query($query, $fetchMode);
            } else {
                $result = parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            $this->record('query', $query, null, (microtime(true) - $startedAt) * 1000, $result !== false, null);
            return $result;
        } catch (Throwable $exception) {
            $this->record('query', $query, null, (microtime(true) - $startedAt) * 1000, false, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    private function record(
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error
    ): void {
        if ($this->queryProfiler === null || !$this->queryProfiler->isEnabled()) {
            return;
        }

        $this->queryProfiler->recordQuery(
            $this->connectionLabel,
            $mode,
            $sql,
            $params,
            $durationMs,
            $success,
            $error
        );
    }
}
