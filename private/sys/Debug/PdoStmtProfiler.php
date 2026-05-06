<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/PdoStmtProfiler.php
 * PDOStatement subclass that records bind values and execution timing through QueryProfiler.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

use PDO;
use Throwable;

/**
 * PDOStatement subclass that captures bind values and feeds execution timing into the query profiler.
 */
final class PdoStmtProfiler extends \PDOStatement
{
    private string $connectionLabel = 'app';
    /** @var array<int|string, mixed> */
    private array $boundValues = [];
    private ?QueryProfiler $queryProfiler = null;

    /**
     * Receives the connection label and optional profiler injected by PdoQueryProfiler via ATTR_STATEMENT_CLASS.
     *
     * @param string                      $connectionLabel Profiler label identifying the parent connection.
     * @param QueryProfiler|null $queryProfiler   Profiler instance; null disables recording for this statement.
     */
    protected function __construct(string $connectionLabel = 'app', ?QueryProfiler $queryProfiler = null)
    {
        $this->connectionLabel = strtolower(trim($connectionLabel)) !== '' ? strtolower(trim($connectionLabel)) : 'app';
        $this->queryProfiler = $queryProfiler;
    }

    /**
     * Binds a value and records it for inclusion in the profiler payload on execute.
     *
     * @param string|int $param Parameter identifier (named :placeholder or positional index).
     * @param mixed      $value Value to bind.
     * @param int        $type  PDO::PARAM_* type constant.
     * @return bool True on success.
     */
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    /**
     * Binds a variable by reference and records a placeholder in the profiler payload.
     *
     * The recorded value is the sentinel string '[bound-by-reference]' because the
     * variable's final value is not known until execute() is called.
     *
     * @param string|int $param         Parameter identifier (named :placeholder or positional index).
     * @param mixed      $var           Variable to bind by reference.
     * @param int        $type          PDO::PARAM_* type constant.
     * @param int        $maxLength     Pre-allocation hint for output parameters.
     * @param mixed      $driverOptions Driver-specific options.
     * @return bool True on success.
     */
    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        $this->boundValues[$param] = '[bound-by-reference]';
        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    /**
     * Executes the statement and records its query, parameters, and duration in the profiler.
     *
     * @param array<int|string, mixed>|null $params Optional parameter array; merged with any bound values.
     * @return bool True on success.
     */
    public function execute(?array $params = null): bool
    {
        $startedAt = microtime(true);
        $queryString = trim((string) $this->queryString);
        $payload = $params ?? $this->boundValues;

        try {
            $result = parent::execute($params);
            $this->record($queryString, $payload, (microtime(true) - $startedAt) * 1000, $result, null);
            return $result;
        } catch (Throwable $exception) {
            $this->record($queryString, $payload, (microtime(true) - $startedAt) * 1000, false, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function record(string $sql, array $params, float $durationMs, bool $success, ?string $error): void
    {
        if ($sql === '' || $this->queryProfiler === null || !$this->queryProfiler->isEnabled()) {
            return;
        }

        $this->queryProfiler->recordQuery(
            $this->connectionLabel,
            'execute',
            $sql,
            $params,
            $durationMs,
            $success,
            $error
        );
    }
}
