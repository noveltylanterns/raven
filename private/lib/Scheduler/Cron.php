<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scheduler/Cron.php
 * Shared fallback scheduler trigger for web entrypoints.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Scheduler;

/**
 * Runs the throttled fallback scheduler used by public and panel web traffic.
 */
final class Cron
{
    /**
     * Executes the passive scheduler trigger when the current scope allows it and the throttle window expired.
     *
     * The entrypoint owns the scope-specific policy decision (`always`, `panel`, helper-path suppression, etc.).
     * This helper only owns the shared throttle stamp, optional runtime preparation, and `runDue()` invocation.
     *
     * @param array<string, mixed> $rvn Active Raven runtime container.
     * @param string $root Absolute project root path.
     * @param bool $shouldRun Whether the current request scope should trigger fallback scheduling.
     * @param callable(array<string, mixed>): array<string, mixed>|null $prepareRuntime Optional callback that enriches the runtime before jobs execute.
     * @return void
     */
    public static function runIfDue(array $rvn, string $root, bool $shouldRun, ?callable $prepareRuntime = null): void
    {
        // Caller scope can suppress scheduler execution for this request entirely.
        if (!$shouldRun) {
            return;
        }

        $scheduler = $rvn['scheduler'] ?? null;
        // Runtime must expose the scheduler registry service to continue.
        if (!$scheduler instanceof Registry) {
            return;
        }

        $schedulerStampFile = $root . '/private/dat/scheduler_last_run';
        $lastRun = is_file($schedulerStampFile) ? (int) @file_get_contents($schedulerStampFile) : 0;
        // Throttle fallback scheduler invocations to once per minute.
        if (time() - $lastRun < 60) {
            return;
        }

        // Optional runtime preparation can inject extra context/services before jobs run.
        if ($prepareRuntime !== null) {
            $preparedRuntime = $prepareRuntime($rvn);
            // Only array payloads replace the runtime container.
            if (is_array($preparedRuntime)) {
                $rvn = $preparedRuntime;
                $scheduler = $rvn['scheduler'] ?? $scheduler;
            }
        }

        @file_put_contents($schedulerStampFile, (string) time());

        // Flush response early when available so background jobs do not block request latency.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $scheduler->runDue(['root' => $root, 'rvn' => $rvn]);
    }
}
