<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Profiling;

use PDO;
use Throwable;

final class ProfiledPDOStatement extends \PDOStatement
{
    private string $connectionLabel = 'app';
    /** @var array<int|string, mixed> */
    private array $boundValues = [];
    private ?QueryProfilerInterface $queryProfiler = null;

    protected function __construct(string $connectionLabel = 'app', ?QueryProfilerInterface $queryProfiler = null)
    {
        $this->connectionLabel = strtolower(trim($connectionLabel)) !== '' ? strtolower(trim($connectionLabel)) : 'app';
        $this->queryProfiler = $queryProfiler;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

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

