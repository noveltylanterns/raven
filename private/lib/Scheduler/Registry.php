<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scheduler/Registry.php
 * System-wide scheduler registry for core and extension background jobs.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scheduler;

/**
 * System-wide registry for scheduled background jobs.
 *
 * Both core and extensions can register jobs. Each job declares a logical
 * owner key, a name, a minimum interval in seconds, and a run callable.
 * Last-run timestamps are stored under `.tmp/cron/{owner}/{name}.ts` so
 * jobs are not re-triggered until their interval elapses.
 *
 * Extensions opt in to the scheduler by setting `scheduler: true` in ext.php.
 * When opted in, core calls `addExtensionSource()` during bootstrap, and the
 * scheduler lazily loads `cron.php` from that extension the first time a
 * run or status check is requested.
 *
 * Core-owned jobs (for example the built-in page-schedule job that flips
 * publish/draft status) are registered directly via `registerJob()` during
 * `private/Raven.php` bootstrap.
 */
final class Registry
{
    private string $root;

    /**
     * Registered jobs keyed by "{owner}::{name}" for O(1) duplicate detection.
     *
     * @var array<string, array{owner: string, name: string, interval: int, run: callable}>
     */
    private array $jobs = [];

    /**
     * Extension directory names whose cron providers have not yet been loaded.
     *
     * @var array<int, string>
     */
    private array $pendingExtensions = [];

    /**
     * Whether all pending extension cron providers have been loaded.
     *
     * Prevents repeated filesystem hits when multiple callers trigger the load.
     */
    private bool $extensionJobsLoaded = false;

    /**
     * @param string $root Project root path.
     */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    /**
     * Registers one job directly into the scheduler.
     *
     * Use this for core-owned jobs or for extension boot hooks that need
     * to register jobs inline rather than via extension `cron.php`.
     *
     * @param string   $owner    Logical owner key (for example `core` or an extension directory name).
     * @param string   $name     Job name slug (`^[a-z0-9][a-z0-9_-]{0,63}$`).
     * @param int      $interval Minimum seconds between runs; must be at least 1.
     * @param callable $run      Called with `array $context` when the job is due.
     *                           Context keys: `root` (string), `rvn` (array).
     */
    public function registerJob(string $owner, string $name, int $interval, callable $run): void
    {
        if ($interval < 1) {
            return;
        }

        $key = $owner . '::' . $name;
        // First registration wins — prevents duplicate or conflicting jobs per owner+name pair.
        if (!isset($this->jobs[$key])) {
            $this->jobs[$key] = [
                'owner' => $owner,
                'name' => $name,
                'interval' => $interval,
                'run' => $run,
            ];
        }
    }

    /**
     * Marks an extension directory as having a cron provider to load.
     *
     * The file is not loaded immediately — it is deferred until the first
     * `runDue()` or `getStatus()` call, keeping the main bootstrap lean.
     *
     * @param string $directoryName Extension directory name.
     */
    public function addExtensionSource(string $directoryName): void
    {
        if (!in_array($directoryName, $this->pendingExtensions, true)) {
            $this->pendingExtensions[] = $directoryName;
            // Adding new sources invalidates the "all loaded" flag.
            $this->extensionJobsLoaded = false;
        }
    }

    /**
     * Returns all currently registered jobs, triggering a lazy extension load first.
     *
     * @return array<string, array{owner: string, name: string, interval: int, run: callable}>
     */
    public function jobs(): array
    {
        $this->ensureExtensionJobsLoaded();
        return $this->jobs;
    }

    /**
     * Runs all jobs that are currently due and returns a per-job result map.
     *
     * Triggers a lazy load of all pending extension cron providers before
     * checking which jobs need to run.
     *
     * Result entries are keyed by `"{owner}::{name}"` and contain:
     *   - `ran`    (bool)        — true when the job callable was invoked.
     *   - `error`  (string|null) — exception message if the callable threw.
     *   - `reason` (string|null) — skip reason when `ran` is false and no error occurred.
     *
     * @param array<string, mixed> $context Context passed to each job callable; must include `root` and `rvn`.
     * @return array<string, array{ran: bool, error: string|null, reason: string|null}>
     */
    public function runDue(array $context): array
    {
        $this->ensureExtensionJobsLoaded();

        $results = [];
        $now = time();

        foreach ($this->jobs as $key => $job) {
            $owner = $job['owner'];
            $name = $job['name'];
            $interval = $job['interval'];
            $lastRun = $this->getLastRunTime($owner, $name);

            // Skip if the interval has not yet elapsed since the last successful run.
            if ($lastRun !== null && ($now - $lastRun) < $interval) {
                $results[$key] = ['ran' => false, 'error' => null, 'reason' => 'not_due'];
                continue;
            }

            try {
                ($job['run'])($context);
                $this->setLastRunTime($owner, $name, $now);
                $results[$key] = ['ran' => true, 'error' => null, 'reason' => null];
            } catch (\Throwable $exception) {
                // Do not update the timestamp on failure so the job retries next cycle.
                $results[$key] = ['ran' => false, 'error' => $exception->getMessage(), 'reason' => null];
                error_log('Raven scheduler job "' . $key . '" failed: ' . $exception->getMessage());
            }
        }

        return $results;
    }

    /**
     * Returns a status snapshot for all registered jobs.
     *
     * Triggers a lazy load of all pending extension cron providers.
     *
     * Each entry is keyed by `"{owner}::{name}"` and contains:
     *   - `owner`      (string)   — owner key.
     *   - `name`       (string)   — job name.
     *   - `interval`   (int)      — configured minimum interval in seconds.
     *   - `last_run`   (int|null) — Unix timestamp of last successful run, or null if never.
     *   - `next_due`   (int|null) — Unix timestamp when the job next becomes due, or null if never run.
     *   - `overdue`    (bool)     — true when the job is past due (never run counts as overdue).
     *
     * @return array<string, array{owner: string, name: string, interval: int, last_run: int|null, next_due: int|null, overdue: bool}>
     */
    public function getStatus(): array
    {
        $this->ensureExtensionJobsLoaded();

        $status = [];
        $now = time();

        foreach ($this->jobs as $key => $job) {
            $lastRun = $this->getLastRunTime($job['owner'], $job['name']);
            $nextDue = $lastRun !== null ? $lastRun + $job['interval'] : null;
            $overdue = $nextDue === null || $now >= $nextDue;

            $status[$key] = [
                'owner' => $job['owner'],
                'name' => $job['name'],
                'interval' => $job['interval'],
                'last_run' => $lastRun,
                'next_due' => $nextDue,
                'overdue' => $overdue,
            ];
        }

        return $status;
    }

    /**
     * Returns the Unix timestamp of the last successful run for a given job, or null if never run.
     *
     * @param string $owner Owner key identifying the extension or core subsystem.
     * @param string $name Job name within that owner namespace.
     * @return int|null Unix timestamp of the last successful run, or null if the job has never run.
     */
    public function getLastRunTime(string $owner, string $name): ?int
    {
        $path = $this->timestampPath($owner, $name);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $ts = (int) trim($raw);
        return $ts > 0 ? $ts : null;
    }

    /**
     * Loads all pending extension cron providers and registers their jobs.
     *
     * Called lazily before any operation that inspects or runs jobs.
     * Safe to call multiple times — subsequent calls are no-ops once all
     * pending sources have been loaded.
     */
    private function ensureExtensionJobsLoaded(): void
    {
        if ($this->extensionJobsLoaded) {
            return;
        }

        foreach ($this->pendingExtensions as $directoryName) {
            $this->loadExtensionCronFile($directoryName);
        }

        $this->extensionJobsLoaded = true;
    }

    /**
     * Loads `cron.php` for one extension and registers valid job definitions.
     *
     * The file must return an array of job definition maps. Each entry requires:
     *   - `name`     (string)   — job name slug (`^[a-z0-9][a-z0-9_-]{0,63}$`).
     *   - `interval` (int)      — minimum seconds between runs.
     *   - `run`      (callable) — called with a context array when the job is due.
     *
     * Invalid entries are silently skipped; a file that throws causes that
     * extension's jobs to be skipped without aborting the rest of the load.
     *
     * @param string $directoryName Extension directory name.
     */
    private function loadExtensionCronFile(string $directoryName): void
    {
        $extensionRoot = $this->root . '/private/ext/' . $directoryName;
        $cronPath = \Raven\Lib\Extension\Resolver::providerPath($extensionRoot, 'cron.php');
        if ($cronPath === null) {
            return;
        }

        try {
            /** @var mixed $raw */
            $raw = require $cronPath;
        } catch (\Throwable $exception) {
            error_log(
                'Raven scheduler failed to load cron.php for extension "' . $directoryName . '": '
                . $exception->getMessage()
            );
            return;
        }

        if (!is_array($raw)) {
            return;
        }

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) !== 1) {
                continue;
            }

            $interval = (int) ($entry['interval'] ?? 0);
            if ($interval < 1) {
                continue;
            }

            $run = $entry['run'] ?? null;
            if (!is_callable($run)) {
                continue;
            }

            $this->registerJob($directoryName, $name, $interval, $run);
        }
    }

    /**
     * Persists the last-run timestamp for a job to the `.tmp/cron/` directory.
     *
     * Silently skips persistence when the directory cannot be created; the job
     * has already run and will simply be treated as overdue on the next cycle.
     *
     * @param string $owner Owner key.
     * @param string $name  Job name.
     * @param int    $time  Unix timestamp to record.
     */
    private function setLastRunTime(string $owner, string $name, int $time): void
    {
        $dir = $this->root . '/.tmp/cron/' . $owner;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents($this->timestampPath($owner, $name), (string) $time);
    }

    /**
     * Returns the absolute path to the timestamp file for a job.
     *
     * @param string $owner Owner key.
     * @param string $name  Job name.
     */
    private function timestampPath(string $owner, string $name): string
    {
        return $this->root . '/.tmp/cron/' . $owner . '/' . $name . '.ts';
    }
}
