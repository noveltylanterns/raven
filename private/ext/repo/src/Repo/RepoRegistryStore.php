<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/src/Repo/RepoRegistryStore.php
 * File-backed repository registry store for the Repo extension.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Repo;

use RuntimeException;

/**
 * Persists configured repository records and per-repo overrides.
 */
final class RepoRegistryStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Returns the absolute registry file path.
     *
     * @param void
     * @return string Absolute `private/dat/ext/repo/.registry.json` path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns all normalized repository records keyed by slug.
     *
     * @param void
     * @return array<string, array<string, mixed>> Registry rows keyed by slug.
     */
    public function all(): array
    {
        $payload = RepoStorageSupport::loadJsonObjectFile($this->path);
        $rawRepos = is_array($payload['repos'] ?? null) ? $payload['repos'] : [];
        $repos = [];
        foreach ($rawRepos as $slug => $record) {
            if (!is_array($record)) {
                continue;
            }

            $normalized = $this->normalizeRecord($record, is_string($slug) ? $slug : null);
            if ($normalized === null) {
                continue;
            }

            $repos[$normalized['slug']] = $normalized;
        }

        uasort($repos, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $repos;
    }

    /**
     * Returns one normalized repository row by slug.
     *
     * @param string $slug Requested repository slug.
     * @return array<string, mixed>|null Matching record when present.
     */
    public function get(string $slug): ?array
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $repos = $this->all();
        return is_array($repos[$normalizedSlug] ?? null) ? $repos[$normalizedSlug] : null;
    }

    /**
     * Inserts or updates one repository row.
     *
     * @param array<string, mixed> $record Raw repository payload.
     * @return array<string, mixed> Normalized row that was written.
     */
    public function put(array $record): array
    {
        $existing = $this->get((string) ($record['slug'] ?? ''));
        $normalized = $this->normalizeRecord($record, null, $existing);
        if ($normalized === null) {
            throw new RuntimeException('Repository slug is required.');
        }

        $repos = $this->all();
        $repos[$normalized['slug']] = $normalized;
        $this->saveAll($repos);
        return $normalized;
    }

    /**
     * Deletes one repository row by slug.
     *
     * @param string $slug Repository slug.
     * @return array<string, mixed>|null Removed row when present.
     */
    public function remove(string $slug): ?array
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $repos = $this->all();
        $existing = is_array($repos[$normalizedSlug] ?? null) ? $repos[$normalizedSlug] : null;
        if ($existing === null) {
            return null;
        }

        unset($repos[$normalizedSlug]);
        $this->saveAll($repos);
        return $existing;
    }

    /**
     * @param array<string, array<string, mixed>> $repos
     * @return void
     */
    private function saveAll(array $repos): void
    {
        ksort($repos);
        RepoStorageSupport::writeJsonObjectFile(
            $this->path,
            ['repos' => $repos]
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param string|null $fallbackSlug
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>|null
     */
    private function normalizeRecord(array $record, ?string $fallbackSlug = null, ?array $existing = null): ?array
    {
        $slug = $this->normalizeSlug((string) ($record['slug'] ?? $fallbackSlug ?? ($existing['slug'] ?? '')));
        if ($slug === '') {
            return null;
        }

        // Sync and metadata refreshes persist partial repo updates, so merge them onto the
        // existing row before normalization instead of resetting operator-owned fields.
        $merged = $existing !== null ? array_replace($existing, $record) : $record;

        $now = gmdate('c');
        $label = trim((string) ($merged['label'] ?? $merged['name'] ?? $slug));
        if ($label === '') {
            $label = $slug;
        }

        $visibility = strtolower(trim((string) ($merged['visibility'] ?? 'system')));
        $allowedVisibility = ['system', 'private', 'public_meta_private_objects', 'public_browser', 'public_downloads'];
        if (!in_array($visibility, $allowedVisibility, true)) {
            $visibility = 'system';
        }

        $storage = strtolower(trim((string) ($merged['storage'] ?? 'system')));
        if (!in_array($storage, ['system', 'local', 'public'], true)) {
            $storage = 'system';
        }

        $autoUpdate = strtolower(trim((string) ($merged['auto_update'] ?? 'system')));
        if (!in_array($autoUpdate, ['system', 'on', 'off'], true)) {
            $autoUpdate = 'system';
        }

        $updateFrequency = strtolower(trim((string) ($merged['update_frequency'] ?? 'system')));
        if (!in_array($updateFrequency, ['system', 'hourly', 'daily', 'weekly', 'monthly'], true)) {
            $updateFrequency = 'system';
        }

        $sources = $this->normalizeSources($merged['sources'] ?? []);
        $primarySourceUrl = trim((string) ($record['primary_source_url'] ?? ''));
        if ($primarySourceUrl !== '') {
            $sources = $this->normalizeSources([['url' => $primarySourceUrl]]) ?: $sources;
        }

        $publicBranch = $this->normalizeBranchName((string) ($merged['public_branch'] ?? ''));
        $defaultBranch = $this->normalizeBranchName((string) ($merged['default_branch'] ?? ''));

        return [
            'slug' => $slug,
            'label' => $label,
            'description' => trim((string) ($merged['description'] ?? '')),
            'notes' => trim((string) ($merged['notes'] ?? '')),
            'visibility' => $visibility,
            'storage' => $storage,
            'auto_update' => $autoUpdate,
            'update_frequency' => $updateFrequency,
            'sources' => $sources,
            'default_branch' => $defaultBranch,
            'public_branch' => $publicBranch,
            'last_attempted_sync_at' => $this->normalizeTimestamp($merged['last_attempted_sync_at'] ?? null),
            'last_successful_sync_at' => $this->normalizeTimestamp($merged['last_successful_sync_at'] ?? null),
            'last_error' => trim((string) ($merged['last_error'] ?? '')),
            'last_error_at' => $this->normalizeTimestamp($merged['last_error_at'] ?? null),
            'last_sync_summary' => trim((string) ($merged['last_sync_summary'] ?? '')),
            'last_synced_head' => trim((string) ($merged['last_synced_head'] ?? '')),
            'branch_cache' => $this->normalizeStringList($merged['branch_cache'] ?? [], true),
            'readme_path' => $this->normalizeRepoPath((string) ($merged['readme_path'] ?? '')),
            'license_path' => $this->normalizeRepoPath((string) ($merged['license_path'] ?? '')),
            'disk_usage_bytes' => max(0, (int) ($merged['disk_usage_bytes'] ?? 0)),
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    }

    /**
     * @param mixed $rawSources
     * @return array<int, array{label: string, url: string, branch: string}>
     */
    private function normalizeSources(mixed $rawSources): array
    {
        $sources = [];
        if (!is_array($rawSources)) {
            return [];
        }

        foreach ($rawSources as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = trim((string) ($entry['url'] ?? ''));
            if (!$this->isValidRemoteUrl($url)) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '') {
                $label = 'Origin';
            }

            $sources[] = [
                'label' => $label,
                'url' => $url,
                'branch' => $this->normalizeBranchName((string) ($entry['branch'] ?? '')),
            ];

            if (count($sources) >= 8) {
                break;
            }
        }

        return $sources;
    }

    private function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function normalizeBranchName(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9._\/-]{1,255}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value, bool $allowSlash = false): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $entry) {
            if (!is_scalar($entry)) {
                continue;
            }

            $candidate = trim((string) $entry);
            if ($candidate === '') {
                continue;
            }

            $pattern = $allowSlash
                ? '/^[A-Za-z0-9._\/-]{1,255}$/'
                : '/^[A-Za-z0-9._-]{1,255}$/';
            if (preg_match($pattern, $candidate) !== 1) {
                continue;
            }

            $items[] = $candidate;
        }

        return array_values(array_unique($items));
    }

    private function normalizeRepoPath(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '..') || str_contains($value, "\0")) {
            return '';
        }

        return ltrim($value, '/');
    }

    private function isValidRemoteUrl(string $value): bool
    {
        if ($value === '' || str_contains($value, "\0") || preg_match('/\s/', $value) === 1) {
            return false;
        }

        return strlen($value) <= 2048;
    }
}
