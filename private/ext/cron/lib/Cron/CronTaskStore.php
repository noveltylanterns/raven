<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/lib/Cron/CronTaskStore.php
 * JSON-backed scheduled task storage for the Scheduled Tasks extension.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Ext\Cron;

use RuntimeException;

/**
 * Persists scheduled task definitions into one extension-local JSON file.
 */
final class CronTaskStore
{
    private string $path;

    /**
     * @param string $path Absolute path to the crontab JSON file.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Returns the underlying JSON storage path.
     *
     * @return string Absolute file path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Loads all stored task rows from disk.
     *
     * @return array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool}>
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = is_array($decoded['tasks'] ?? null) ? $decoded['tasks'] : [];
        $tasks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tasks[] = $this->normalizeTask($row);
        }

        return $tasks;
    }

    /**
     * Replaces the stored task list on disk.
     *
     * @param array<int, array{slug: string, label: string, command: string, interval: int, enabled: bool}> $tasks Normalized tasks.
     * @return void
     */
    public function save(array $tasks): void
    {
        $normalized = [];
        foreach ($tasks as $task) {
            $normalized[] = $this->normalizeTask($task);
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create scheduled task storage directory.');
        }

        $payload = json_encode(
            ['tasks' => $normalized],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($payload)) {
            throw new RuntimeException('Unable to encode scheduled tasks JSON.');
        }
        $payload .= "\n";

        $tempPath = $this->path . '.tmp';
        if (file_put_contents($tempPath, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write scheduled task storage.');
        }

        if (!@rename($tempPath, $this->path)) {
            @unlink($tempPath);
            throw new RuntimeException('Unable to finalize scheduled task storage.');
        }

        clearstatcache(true, $this->path);
    }

    /**
     * Returns one normalized task row with safe defaults.
     *
     * @param array<string, mixed> $task Raw task data.
     * @return array{slug: string, label: string, command: string, interval: int, enabled: bool}
     */
    private function normalizeTask(array $task): array
    {
        $slug = trim((string) ($task['slug'] ?? ''));
        $label = trim((string) ($task['label'] ?? ''));
        $command = trim((string) ($task['command'] ?? ''));
        $interval = (int) ($task['interval'] ?? 300);
        if ($interval < 1) {
            $interval = 300;
        }

        return [
            'slug' => $slug,
            'label' => $label,
            'command' => $command,
            'interval' => $interval,
            'enabled' => !empty($task['enabled']),
        ];
    }
}
