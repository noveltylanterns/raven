<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/QueryProfilerPdo.php
 * PDO subclass that records every query through an optional QueryProfiler.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO subclass that feeds every query into the active query profiler when enabled.
 */
final class QueryProfilerPdo extends PDO
{
    private string $connectionLabel;
    private ?QueryProfiler $queryProfiler;

    /**
     * Opens a PDO connection and wires all prepared statements through the profiler.
     *
     * @param string                      $dsn             PDO DSN string.
     * @param string|null                 $username        Database username (null for SQLite).
     * @param string|null                 $password        Database password (null for SQLite).
     * @param array<int|string, mixed>    $options         PDO driver options.
     * @param string                      $connectionLabel Profiler label identifying this connection (e.g., 'app', 'auth').
     * @param QueryProfiler|null $queryProfiler   Profiler instance; null disables query recording.
     * @return void
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        string $connectionLabel = 'app',
        ?QueryProfiler $queryProfiler = null
    ) {
        $this->connectionLabel = strtolower(trim($connectionLabel)) !== '' ? strtolower(trim($connectionLabel)) : 'app';
        $this->queryProfiler = $queryProfiler;
        parent::__construct($dsn, $username, $password, $options);

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            QueryProfilerStatement::class,
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
        // Preserve caller-provided statement class overrides, otherwise enforce profiler-aware statements.
        if (!isset($options[PDO::ATTR_STATEMENT_CLASS])) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [
                QueryProfilerStatement::class,
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
        // Record both successful and failed executions with timing for diagnostics parity.
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
        // Mirror PDO::query overload behavior while wrapping every path with profiler timing.
        try {
            // Pass through the no-fetch-mode signature exactly when caller omits fetch mode.
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
     * Forwards one query record into the active profiler when query collection is enabled.
     *
     * @param string $mode Query execution mode label (`exec`, `query`, `execute`).
     * @param string $sql Executed SQL string.
     * @param array<int|string, mixed>|null $params
     * @param float $durationMs Query duration in milliseconds.
     * @param bool $success Whether execution completed successfully.
     * @param string|null $error Optional driver error text when execution fails.
     * @return void
     */
    private function record(
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error
    ): void {
        // Skip profiler writes entirely when collection is disabled for this connection/request.
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
