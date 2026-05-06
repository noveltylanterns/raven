<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/QueryProfilerInterface.php
 * Contract for query profiler implementations used by PdoQueryProfiler and PdoStmtProfiler.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

interface QueryProfilerInterface
{
    /**
     * Returns true when query recording is active for the current request.
     *
     * @return bool True when the profiler should receive query events.
     */
    public function isEnabled(): bool;

    /**
     * Records a single query event including its execution time and outcome.
     *
     * @param string                        $connection  Profiler label for the originating connection (e.g., 'app', 'auth').
     * @param string                        $mode        Execution mode: 'exec', 'query', or 'execute'.
     * @param string                        $sql         Raw SQL string that was executed.
     * @param array<int|string, mixed>|null $params      Bound parameter map, or null when not applicable.
     * @param float                         $durationMs  Wall-clock execution time in milliseconds.
     * @param bool                          $success     True when the query completed without error.
     * @param string|null                   $error       Error message on failure, null on success.
     */
    public function recordQuery(
        string $connection,
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error = null
    ): void;
}
