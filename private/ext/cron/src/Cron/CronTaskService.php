<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/src/Cron/CronTaskService.php
 * Scheduled task orchestration for the Scheduled Tasks extension.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Ext\Cron;

use Raven\Core\Scheduler;

/**
 * Coordinates scheduled task storage, validation, panel decoration, and execution.
 */
final class CronTaskService
{
    private string $root;
    private CronTaskStore $store;
    private CronShellRunner $runner;

    /**
     * @param string          $root   Project root path.
     * @param CronTaskStore   $store  JSON-backed task store.
     * @param CronShellRunner $runner Shell execution helper.
     */
    public function __construct(string $root, CronTaskStore $store, CronShellRunner $runner)
    {
        $this->root = rtrim($root, '/');
        $this->store = $store;
        $this->runner = $runner;
    }

    /**
     * Returns the extension-local crontab storage path.
     *
     * @return string Absolute JSON file path.
     */
    public function storagePath(): string
    {
        return $this->store->path();
    }

    /**
     * Returns all saved task rows.
     *
     * @return array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool}>
     */
    public function listTasks(): array
    {
        return $this->store->load();
    }

    /**
     * Returns one blank row for the panel repeater.
     *
     * @return array{slug: string, label: string, command: string, interval: int, enabled: bool, last_run: int|null, next_due: int|null, overdue: bool}
     */
    public function blankTask(): array
    {
        return [
            'slug' => '',
            'label' => '',
            'command' => '',
            'interval' => 300,
            'enabled' => true,
            'last_run' => null,
            'next_due' => null,
            'overdue' => false,
        ];
    }

    /**
     * Returns saved tasks decorated with scheduler status for panel display.
     *
     * @param Scheduler|null $scheduler Active scheduler when available.
     * @return array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool, last_run: int|null, next_due: int|null, overdue: bool}>
     */
    public function tasksForPanel(?Scheduler $scheduler = null): array
    {
        $tasks = $this->listTasks();
        if ($tasks === []) {
            return [$this->blankTask()];
        }

        $statusMap = $this->statusMap($scheduler);
        $decorated = [];
        foreach ($tasks as $task) {
            $status = $statusMap[$task['slug']] ?? null;
            $decorated[] = $task + [
                'last_run' => is_array($status) ? ($status['last_run'] ?? null) : null,
                'next_due' => is_array($status) ? ($status['next_due'] ?? null) : null,
                'overdue' => is_array($status) ? !empty($status['overdue']) : false,
            ];
        }

        return $decorated;
    }

    /**
     * Persists one full replacement task list.
     *
     * @param array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool}> $tasks Normalized tasks.
     * @return void
     */
    public function saveTasks(array $tasks): void
    {
        $this->store->save($tasks);
    }

    /**
     * Validates and sanitizes submitted panel rows.
     *
     * @param array<int, mixed>                         $rawRows        Submitted row payloads.
     * @param callable(string, int): string            $textSanitizer  Text sanitizer from Raven input service.
     * @param callable(string): string                 $slugSanitizer  Slug sanitizer from Raven input service.
     * @param callable(mixed, int, int): int|null      $intSanitizer   Integer sanitizer from Raven input service.
     * @return array{
     *   tasks: array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool}>,
     *   rows: array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool, last_run: int|null, next_due: int|null, overdue: bool}>,
     *   errors: array<int, string>
     * }
     */
    public function validateSubmittedTasks(
        array $rawRows,
        callable $textSanitizer,
        callable $slugSanitizer,
        callable $intSanitizer
    ): array {
        $tasks = [];
        $rows = [];
        $errors = [];
        $seenSlugs = [];

        foreach ($rawRows as $index => $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }

            $label = trim((string) $textSanitizer((string) ($rawRow['label'] ?? ''), 120));
            $slug = trim((string) $slugSanitizer((string) ($rawRow['slug'] ?? '')));
            if ($slug === '' && $label !== '') {
                $slug = trim((string) $slugSanitizer($label));
            }

            $command = trim((string) $textSanitizer((string) ($rawRow['command'] ?? ''), 4000));
            $interval = $intSanitizer($rawRow['interval'] ?? null, 1, 31536000);
            $enabled = !empty($rawRow['enabled']);

            $isBlankRow = $label === '' && $slug === '' && $command === '' && ($rawRow['interval'] ?? '') === '';
            if ($isBlankRow) {
                continue;
            }

            if ($label === '' && $slug !== '') {
                $label = $this->humanizeSlug($slug);
            }

            $row = [
                'slug' => $slug,
                'label' => $label,
                'command' => $command,
                'interval' => $interval ?? 300,
                'enabled' => $enabled,
                'last_run' => null,
                'next_due' => null,
                'overdue' => false,
            ];
            $rows[] = $row;

            $rowNumber = (int) $index + 1;
            if ($slug === '') {
                $errors[] = 'Task row ' . $rowNumber . ' needs a job slug or a label that can be converted into one.';
                continue;
            }
            if ($command === '') {
                $errors[] = 'Task row ' . $rowNumber . ' needs a shell command.';
                continue;
            }
            if ($interval === null) {
                $errors[] = 'Task row ' . $rowNumber . ' needs a valid interval in seconds.';
                continue;
            }
            if (isset($seenSlugs[$slug])) {
                $errors[] = 'Task slug "' . $slug . '" is duplicated. Each scheduled task needs a unique slug.';
                continue;
            }

            $seenSlugs[$slug] = true;
            $tasks[] = [
                'slug' => $slug,
                'label' => $label,
                'command' => $command,
                'interval' => $interval,
                'enabled' => $enabled,
            ];
        }

        if ($rows === []) {
            $rows[] = $this->blankTask();
        }

        return [
            'tasks' => $tasks,
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * Returns one scheduler job definition per enabled saved task.
     *
     * @return array<int, array{name: string, interval: int, run: callable}>
     */
    public function schedulerJobs(): array
    {
        $jobs = [];
        foreach ($this->listTasks() as $task) {
            if (!$task['enabled'] || $task['slug'] === '' || $task['command'] === '') {
                continue;
            }

            $slug = $task['slug'];
            $jobs[] = [
                'name' => $slug,
                'interval' => (int) $task['interval'],
                'run' => function (array $context) use ($slug): void {
                    // Reload the task from storage at run time so the scheduler always uses the latest saved command.
                    $this->runTaskBySlug($slug);
                },
            ];
        }

        return $jobs;
    }

    /**
     * Runs one enabled task by slug when the scheduler fires it.
     *
     * @param string $slug Saved task slug.
     * @return void
     */
    public function runTaskBySlug(string $slug): void
    {
        foreach ($this->listTasks() as $task) {
            if ($task['slug'] !== $slug || !$task['enabled']) {
                continue;
            }

            $this->runner->mustRun($task['command'], $this->root);
            return;
        }
    }

    /**
     * Returns scheduler status rows keyed by task slug.
     *
     * @param Scheduler|null $scheduler Active scheduler when available.
     * @return array<string, array{owner: string, name: string, interval: int, last_run: int|null, next_due: int|null, overdue: bool}>
     */
    private function statusMap(?Scheduler $scheduler): array
    {
        if (!$scheduler instanceof Scheduler) {
            return [];
        }

        $status = [];
        foreach ($scheduler->getStatus() as $row) {
            if (!is_array($row) || (string) ($row['owner'] ?? '') !== 'cron') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $status[$name] = $row;
        }

        return $status;
    }

    /**
     * Returns a readable fallback label when a row only provides a slug.
     *
     * @param string $slug Normalized job slug.
     * @return string Human-friendly label.
     */
    private function humanizeSlug(string $slug): string
    {
        $words = str_replace(['-', '_'], ' ', $slug);
        return ucwords(trim($words));
    }
}
