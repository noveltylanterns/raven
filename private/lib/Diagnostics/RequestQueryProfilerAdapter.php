<?php

declare(strict_types=1);

namespace Raven\Lib\Diagnostics;

use Raven\Lib\Database\Profiling\QueryProfilerInterface;

final class RequestQueryProfilerAdapter implements QueryProfilerInterface
{
    public function isEnabled(): bool
    {
        return RequestProfiler::isEnabled();
    }

    public function recordQuery(
        string $connection,
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error = null
    ): void {
        RequestProfiler::recordQuery(
            $connection,
            $mode,
            $sql,
            $params,
            $durationMs,
            $success,
            $error
        );
    }
}
