<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/RequestProfilerAdapter.php
 * Query-profiler adapter that forwards database events into RequestProfiler.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

use Raven\Lib\Database\QueryProfilerInterface;

/**
 * Bridges shared profiled PDO hooks into the request-profiler collector.
 */
final class RequestProfilerAdapter implements QueryProfilerInterface
{
    /**
     * Reports whether the request profiler is enabled for the current request.
     *
     * @return bool True when query events should be forwarded to the collector.
     */
    public function isEnabled(): bool
    {
        return RequestProfiler::isEnabled();
    }

    /**
     * Forwards one database query event into the request-profiler collector.
     *
     * @param string $connection Logical connection label such as `app` or `auth`.
     * @param string $mode Query execution mode reported by the profiled PDO wrapper.
     * @param string $sql Raw SQL statement text.
     * @param array<int|string, mixed>|null $params Bound query parameters.
     * @param float $durationMs Query duration in milliseconds.
     * @param bool $success Whether the statement completed successfully.
     * @param string|null $error Optional driver error text when execution failed.
     * @return void
     */
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
