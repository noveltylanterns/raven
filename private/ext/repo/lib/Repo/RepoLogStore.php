<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/Repo/RepoLogStore.php
 * JSON log store for repository sync and admin events.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Ext\Repo;

/**
 * Persists and filters Repo extension log entries.
 */
final class RepoLogStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Returns the absolute log file path.
     *
     * @param void
     * @return string Absolute `private/dat/ext/repo/.log.json` path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns all log rows sorted newest-first.
     *
     * @param string|null $slug Optional repository slug filter.
     * @return array<int, array<string, mixed>> Matching log rows.
     */
    public function all(?string $slug = null): array
    {
        $rows = $this->normalizeRows(RepoStorageSupport::loadJsonArrayFile($this->path));
        if ($slug !== null && $slug !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['slug'] ?? '') === $slug));
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($right['time'] ?? ''), (string) ($left['time'] ?? ''));
        });

        return $rows;
    }

    /**
     * Appends one normalized log row.
     *
     * @param string $event Stable event key.
     * @param string $level Severity label (`info`, `warn`, or `error`).
     * @param string $message Human-readable event message.
     * @param string|null $slug Optional repository slug.
     * @param array<string, mixed> $context Optional structured context.
     * @return array<string, mixed> The row that was written.
     */
    public function append(string $event, string $level, string $message, ?string $slug = null, array $context = []): array
    {
        $rows = $this->all();
        $row = $this->normalizeRow([
            'time' => gmdate('c'),
            'event' => $event,
            'level' => $level,
            'slug' => $slug,
            'message' => $message,
            'context' => $context,
        ]);
        $rows[] = $row;
        RepoStorageSupport::writeJsonArrayFile($this->path, $rows);
        return $row;
    }

    /**
     * Removes log rows older than the requested number of days.
     *
     * @param int $days Retention window in whole days.
     * @return int Number of deleted rows.
     */
    public function pruneOlderThan(int $days): int
    {
        $days = max(1, $days);
        $cutoff = strtotime('-' . $days . ' days');
        if ($cutoff === false) {
            return 0;
        }

        $rows = $this->all();
        $kept = [];
        $deleted = 0;
        foreach ($rows as $row) {
            $time = strtotime((string) ($row['time'] ?? ''));
            if ($time !== false && $time < $cutoff) {
                $deleted += 1;
                continue;
            }

            $kept[] = $row;
        }

        if ($deleted > 0) {
            RepoStorageSupport::writeJsonArrayFile($this->path, $kept);
        }

        return $deleted;
    }

    /**
     * Returns recent error rows, newest first.
     *
     * @param string|null $slug Optional repository slug filter.
     * @param int $limit Maximum number of rows to return.
     * @return array<int, array<string, mixed>> Matching error rows.
     */
    public function recentErrors(?string $slug = null, int $limit = 5): array
    {
        $limit = max(1, $limit);
        $rows = array_values(array_filter(
            $this->all($slug),
            static fn (array $row): bool => (string) ($row['level'] ?? '') === 'error'
        ));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->normalizeRow($row);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $event = strtolower(trim((string) ($row['event'] ?? 'unknown')));
        if ($event === '') {
            $event = 'unknown';
        }

        $level = strtolower(trim((string) ($row['level'] ?? 'info')));
        if (!in_array($level, ['info', 'warn', 'error'], true)) {
            $level = 'info';
        }

        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
            $slug = '';
        }

        $context = is_array($row['context'] ?? null) ? $row['context'] : [];

        return [
            'time' => trim((string) ($row['time'] ?? gmdate('c'))),
            'event' => $event,
            'level' => $level,
            'slug' => $slug,
            'message' => trim((string) ($row['message'] ?? '')),
            'context' => $context,
        ];
    }
}
