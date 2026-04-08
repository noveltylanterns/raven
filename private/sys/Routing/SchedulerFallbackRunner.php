<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/SchedulerFallbackRunner.php
 * Shared fallback scheduler trigger for web entrypoints.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing;

/**
 * Runs the throttled fallback scheduler used by public and panel web traffic.
 */
final class SchedulerFallbackRunner
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
        if (!$shouldRun) {
            return;
        }

        $scheduler = $rvn['scheduler'] ?? null;
        if (!is_object($scheduler) || !method_exists($scheduler, 'runDue')) {
            return;
        }

        $schedulerStampFile = $root . '/private/dat/scheduler_last_run';
        $lastRun = is_file($schedulerStampFile) ? (int) @file_get_contents($schedulerStampFile) : 0;
        if (time() - $lastRun < 60) {
            return;
        }

        if ($prepareRuntime !== null) {
            $preparedRuntime = $prepareRuntime($rvn);
            if (is_array($preparedRuntime)) {
                $rvn = $preparedRuntime;
                $scheduler = $rvn['scheduler'] ?? $scheduler;
            }
        }

        @file_put_contents($schedulerStampFile, (string) time());

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $scheduler->runDue(['root' => $root, 'rvn' => $rvn]);
    }
}
