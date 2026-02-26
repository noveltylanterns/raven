<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Database/ProfiledPDO.php
 * PDO subclass with lightweight query-timing hooks for debug profiler output.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

use PDO;
use PDOStatement;
use Raven\Core\Debug\RequestProfiler;
use Throwable;

/**
 * Wraps PDO operations and reports query activity into RequestProfiler.
 */
final class ProfiledPDO extends PDO
{
    private string $connectionLabel;

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        string $connectionLabel = 'app'
    ) {
        $this->connectionLabel = strtolower(trim($connectionLabel)) !== '' ? strtolower(trim($connectionLabel)) : 'app';
        parent::__construct($dsn, $username, $password, $options);

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProfiledPDOStatement::class, [$this->connectionLabel]]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (!isset($options[PDO::ATTR_STATEMENT_CLASS])) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [ProfiledPDOStatement::class, [$this->connectionLabel]];
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
        if (!RequestProfiler::isEnabled()) {
            return;
        }

        RequestProfiler::recordQuery(
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

