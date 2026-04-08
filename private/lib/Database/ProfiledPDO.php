<?php

declare(strict_types=1);

namespace Raven\Lib\Database;

use PDO;
use PDOStatement;
use Throwable;

final class ProfiledPDO extends PDO
{
    private string $connectionLabel;
    private ?QueryProfilerInterface $queryProfiler;

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

