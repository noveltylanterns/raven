<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/Repo/RepoService.php
 * Core repository management service for the Repo extension.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Ext\Repo;

use Raven\Lib\Archive\Folder as ArchiveDelete;
use Raven\Lib\Extension\Public\MarkdownFileLoader;
use Raven\Lib\Format\Git;
use RuntimeException;

/**
 * Owns file-backed settings, registry CRUD, sync operations, and public read helpers.
 */
final class RepoService implements MarkdownFileLoader
{
    private const TEXT_PREVIEW_BYTES = 524288;

    private string $projectRoot;
    /** @var object $config */
    private object $config;
    private RepoSettingsStore $settingsStore;
    private RepoRegistryStore $registryStore;
    private RepoLogStore $logStore;
    private Git $git;
    private ArchiveDelete $directoryTree;
    private string $localRoot;
    private string $publicRoot;

    /**
     * @param object $config Raven config service from the shared app container.
     */
    public function __construct(
        string $projectRoot,
        object $config,
        RepoSettingsStore $settingsStore,
        RepoRegistryStore $registryStore,
        RepoLogStore $logStore,
        Git $git,
        ArchiveDelete $directoryTree,
        string $localRoot,
        string $publicRoot
    ) {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->config = $config;
        $this->settingsStore = $settingsStore;
        $this->registryStore = $registryStore;
        $this->logStore = $logStore;
        $this->git = $git;
        $this->directoryTree = $directoryTree;
        $this->localRoot = rtrim($localRoot, '/');
        $this->publicRoot = rtrim($publicRoot, '/');
    }

    /**
     * Returns normalized global extension settings.
     *
     * @param void
     * @return array<string, mixed> Repo module settings payload.
     */
    public function settings(): array
    {
        return $this->settingsStore->load();
    }

    /**
     * Persists global extension settings and prunes logs to the new retention target.
     *
     * @param array<string, mixed> $settings Raw settings payload from panel input.
     * @return array<string, mixed> Normalized settings that were written.
     */
    public function saveSettings(array $settings): array
    {
        $normalized = $this->settingsStore->save($settings);
        $pruned = $this->logStore->pruneOlderThan((int) ($normalized['log_prune_days'] ?? 30));

        $this->log(
            'settings_updated',
            'info',
            'Repo extension settings were updated.',
            null,
            ['pruned_log_rows' => $pruned]
        );

        return $normalized;
    }

    /**
     * Returns all configured repositories decorated with effective runtime fields.
     *
     * @param void
     * @return array<int, array<string, mixed>> Decorated repository rows.
     */
    public function repoList(): array
    {
        $items = [];
        foreach ($this->registryStore->all() as $repo) {
            $items[] = $this->decorateRepo($repo);
        }

        return $items;
    }

    /**
     * Returns all publicly visible repositories decorated for public rendering.
     *
     * @param void
     * @return array<int, array<string, mixed>> Publicly listed repository rows.
     */
    public function publicRepoList(): array
    {
        return array_values(array_filter(
            $this->repoList(),
            static fn (array $repo): bool => !empty($repo['is_public_listed'])
        ));
    }

    /**
     * Returns one decorated repository row by slug.
     *
     * @param string $slug Repository slug.
     * @return array<string, mixed>|null Decorated row when present.
     */
    public function getRepo(string $slug): ?array
    {
        $repo = $this->registryStore->get($slug);
        return $repo === null ? null : $this->decorateRepo($repo);
    }

    /**
     * Returns a starter payload for a new repository form.
     *
     * @param void
     * @return array<string, mixed> Default row used by the create/import UI.
     */
    public function newRepoDefaults(): array
    {
        return $this->decorateRepo([
            'slug' => '',
            'label' => '',
            'description' => '',
            'notes' => '',
            'visibility' => 'private',
            'storage' => 'local',
            'auto_update' => 'system',
            'update_frequency' => 'system',
            'sources' => [
                ['label' => 'Origin', 'url' => '', 'branch' => ''],
                ['label' => 'Mirror', 'url' => '', 'branch' => ''],
                ['label' => 'Fallback', 'url' => '', 'branch' => ''],
            ],
            'default_branch' => '',
            'public_branch' => '',
            'last_attempted_sync_at' => null,
            'last_successful_sync_at' => null,
            'last_error' => '',
            'last_error_at' => null,
            'last_sync_summary' => '',
            'last_synced_head' => '',
            'branch_cache' => [],
            'readme_path' => '',
            'license_path' => '',
            'disk_usage_bytes' => 0,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);
    }

    /**
     * Creates or updates one repository record.
     *
     * @param array<string, mixed> $payload Raw repository form payload.
     * @return array<string, mixed> Decorated row that was saved.
     */
    public function saveRepo(array $payload): array
    {
        $slug = $this->normalizeSlug((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            throw new RuntimeException('Repository slug is required.');
        }

        $existing = $this->registryStore->get($slug);
        $requestedVisibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? 'system'));
        $effectiveVisibility = $this->resolveVisibilityValue($requestedVisibility);
        // Repository placement is derived from visibility so operators only manage one exposure setting.
        $safeStorage = $this->storageForVisibility($effectiveVisibility);
        $sources = $this->normalizeSourceRows($payload['sources'] ?? []);
        if ($sources === []) {
            throw new RuntimeException('At least one upstream source URL is required.');
        }

        $requestedPublicBranch = $this->normalizeRefName((string) ($payload['public_branch'] ?? ''));
        if ($requestedPublicBranch === '') {
            // Keep using the last concrete default branch instead of reintroducing a blank alias state.
            $requestedPublicBranch = $this->normalizeRefName((string) ($existing['public_branch'] ?? ''));
        }
        if ($requestedPublicBranch === '') {
            $requestedPublicBranch = $this->normalizeRefName((string) ($existing['default_branch'] ?? ''));
        }

        $saved = $this->registryStore->put([
            'slug' => $slug,
            'label' => trim((string) ($payload['label'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'visibility' => $requestedVisibility,
            'storage' => $safeStorage,
            'auto_update' => $this->normalizeAutoUpdate((string) ($payload['auto_update'] ?? 'system')),
            'update_frequency' => $this->normalizeFrequency((string) ($payload['update_frequency'] ?? 'system'), true),
            'sources' => $sources,
            'public_branch' => $requestedPublicBranch,
        ]);

        $decorated = $this->decorateRepo($saved);
        $this->migrateRepositoryStorageIfNeeded($existing, $decorated);

        $this->log(
            'repo_saved',
            'info',
            'Repository settings were saved.',
            $slug,
            ['visibility' => $decorated['effective_visibility'], 'storage' => $decorated['effective_storage']]
        );

        return $this->getRequiredRepo($slug);
    }

    /**
     * Deletes one repository row and its mirrored storage.
     *
     * @param string $slug Repository slug.
     * @return array<string, mixed>|null Removed repository row when present.
     */
    public function deleteRepo(string $slug): ?array
    {
        $repo = $this->getRepo($slug);
        if ($repo === null) {
            return null;
        }

        foreach ([$this->localRepositoryPath($slug), $this->publicRepositoryPath($slug)] as $path) {
            $this->directoryTree->removeTree($path);
        }

        $removed = $this->registryStore->remove($slug);
        if ($removed !== null) {
            $this->log('repo_deleted', 'warn', 'Repository was deleted.', $slug);
        }

        return $repo;
    }

    /**
     * Syncs one repository mirror from its configured upstream list.
     *
     * @param string $slug Repository slug.
     * @return array<string, mixed> Decorated repository row after sync.
     */
    public function syncRepo(string $slug): array
    {
        $repo = $this->getRequiredRepo($slug);
        $sources = is_array($repo['sources'] ?? null) ? $repo['sources'] : [];
        if ($sources === []) {
            throw new RuntimeException('Repository has no configured upstream sources.');
        }

        $attemptedAt = gmdate('c');
        $this->registryStore->put([
            'slug' => $slug,
            'last_attempted_sync_at' => $attemptedAt,
            'last_error' => '',
            'last_error_at' => null,
        ]);
        $this->log('sync_started', 'info', 'Repository sync started.', $slug);

        $repoPath = (string) ($repo['repository_path'] ?? '');
        if ($repoPath === '') {
            throw new RuntimeException('Repository storage path could not be resolved.');
        }

        $errors = [];
        foreach ($sources as $index => $source) {
            if (!is_array($source)) {
                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));
            $label = trim((string) ($source['label'] ?? ''));
            if ($url === '') {
                continue;
            }

            try {
                $this->syncMirrorFromSource($repoPath, $url);
                if (!empty($repo['public_object_access'])) {
                    $this->git->mustRun(['update-server-info'], $repoPath);
                }

                $updated = $this->refreshRepositoryMetadata(
                    $slug,
                    $repoPath,
                    $attemptedAt,
                    $label !== '' ? $label : ('Source ' . (string) ($index + 1)),
                    $index
                );

                $this->log(
                    'sync_succeeded',
                    'info',
                    'Repository sync completed successfully.',
                    $slug,
                    ['source_label' => $label !== '' ? $label : ('Source ' . (string) ($index + 1))]
                );

                return $updated;
            } catch (\Throwable $exception) {
                $errors[] = $this->sanitizeErrorMessage($exception->getMessage());
            }
        }

        $message = $errors !== []
            ? $errors[0]
            : 'Repository sync failed before Git could report an error.';

        $this->registryStore->put([
            'slug' => $slug,
            'last_attempted_sync_at' => $attemptedAt,
            'last_error' => $message,
            'last_error_at' => gmdate('c'),
            'last_sync_summary' => 'Sync failed.',
        ]);
        $this->log('sync_failed', 'error', $message, $slug);

        throw new RuntimeException($message);
    }

    /**
     * Returns recent or complete log rows, newest first.
     *
     * @param string|null $slug Optional repository slug filter.
     * @param int $limit Maximum number of rows to return.
     * @return array<int, array<string, mixed>> Matching log rows.
     */
    public function logs(?string $slug = null, int $limit = 200): array
    {
        $rows = $this->logStore->all($slug);
        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Returns recent error rows for list and edit-page warning banners.
     *
     * @param string|null $slug Optional repository slug filter.
     * @param int $limit Maximum number of rows to return.
     * @return array<int, array<string, mixed>> Recent error rows.
     */
    public function recentErrors(?string $slug = null, int $limit = 5): array
    {
        return $this->logStore->recentErrors($slug, $limit);
    }

    /**
     * Returns whether this Raven branch exposes the shared scheduler registry.
     *
     * @param void
     * @return bool True when core exposes the scheduler registry contract.
     */
    public function schedulerAvailable(): bool
    {
        return class_exists(\Raven\Lib\Scheduler\Registry::class);
    }

    /**
     * Returns the active fallback scheduler mode from system configuration.
     *
     * @param void
     * @return string One of `off`, `panel`, or `always`.
     */
    public function schedulerMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('site.scheduler', 'always')));
        return in_array($mode, ['off', 'panel', 'always'], true) ? $mode : 'always';
    }

    /**
     * Runs one scheduler pass across all repositories whose auto-update windows are due.
     *
     * The shared scheduler fires on a short cadence. This method applies each
     * repository's effective auto-update and frequency settings so one job can
     * safely service hourly, daily, weekly, and monthly mirrors together.
     *
     * @param int|null $now Optional Unix timestamp override for deterministic checks.
     * @return array{checked: int, due: int, synced: int, failed: int, skipped: int, pruned_logs: int, results: array<int, array{slug: string, status: string, message: string}>} Scheduler pass summary.
     */
    public function runScheduledSyncPass(?int $now = null): array
    {
        $summary = [
            'checked' => 0,
            'due' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
            'pruned_logs' => 0,
            'results' => [],
        ];

        $summary['pruned_logs'] = $this->logStore->pruneOlderThan((int) ($this->settings()['log_prune_days'] ?? 30));
        $now = $now ?? time();

        foreach ($this->repoList() as $repo) {
            $slug = $this->normalizeSlug((string) ($repo['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $summary['checked'] += 1;

            if (empty($repo['effective_auto_update']) || !$this->repoIsDueForScheduledSync($repo, $now)) {
                $summary['skipped'] += 1;
                continue;
            }

            $summary['due'] += 1;

            try {
                $this->syncRepo($slug);
                $summary['synced'] += 1;
                $summary['results'][] = [
                    'slug' => $slug,
                    'status' => 'synced',
                    'message' => 'Repository sync completed successfully.',
                ];
            } catch (\Throwable $exception) {
                $summary['failed'] += 1;
                $summary['results'][] = [
                    'slug' => $slug,
                    'status' => 'failed',
                    'message' => $this->sanitizeErrorMessage($exception->getMessage()),
                ];
            }
        }

        return $summary;
    }

    /**
     * Returns whether one decorated repository row is due for a scheduler-driven sync.
     *
     * @param array<string, mixed> $repo Decorated repository row.
     * @param int $now Unix timestamp used for the due comparison.
     * @return bool True when the repo's effective frequency window has elapsed.
     */
    private function repoIsDueForScheduledSync(array $repo, int $now): bool
    {
        $interval = $this->scheduledFrequencyInterval((string) ($repo['effective_update_frequency'] ?? 'daily'));
        if ($interval < 1) {
            return false;
        }

        $lastAttemptedAt = $this->parseScheduledTimestamp((string) ($repo['last_attempted_sync_at'] ?? ''));
        if ($lastAttemptedAt === null) {
            $lastAttemptedAt = $this->parseScheduledTimestamp((string) ($repo['last_successful_sync_at'] ?? ''));
        }

        if ($lastAttemptedAt === null) {
            return true;
        }

        return ($now - $lastAttemptedAt) >= $interval;
    }

    /**
     * Returns the scheduler interval in seconds for one effective frequency key.
     *
     * @param string $frequency Effective frequency key.
     * @return int Interval in seconds.
     */
    private function scheduledFrequencyInterval(string $frequency): int
    {
        return match ($this->normalizeFrequency($frequency, false)) {
            'hourly' => 3600,
            'weekly' => 604800,
            'monthly' => 2592000,
            default => 86400,
        };
    }

    /**
     * Normalizes one stored timestamp string for scheduler due comparisons.
     *
     * @param string $value ISO-like timestamp string from repo state.
     * @return int|null Unix timestamp, or null when the value is unusable.
     */
    private function parseScheduledTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Builds one public browser payload for a repository detail page.
     *
     * @param string $slug Repository slug.
     * @param string|null $requestedRef Optional branch/ref request.
     * @param string $requestedPath Optional repo-relative path.
     * @param bool $includeReadme Whether directory README auto-include remains enabled.
     * @return array<string, mixed> Repo view payload for public templates.
     */
    public function buildBrowsePayload(
        string $slug,
        ?string $requestedRef = null,
        string $requestedPath = '',
        bool $includeReadme = true
    ): array {
        $repo = $this->getRequiredRepo($slug);
        $payload = [
            'repo' => $repo,
            'mode' => 'metadata',
            'ref' => '',
            'path' => '',
            'entries' => [],
            'breadcrumbs' => [],
            'readme' => null,
            'file' => null,
            'license_path' => (string) ($repo['license_path'] ?? ''),
        ];

        if (!$this->isValidBareRepoPath((string) ($repo['repository_path'] ?? ''))) {
            return array_replace($payload, ['notice' => 'This repository has not been synced yet.']);
        }

        $repoPath = (string) ($repo['repository_path'] ?? '');
        $ref = $this->resolvePreferredRef($repo, $requestedRef, $repoPath);
        $path = $this->sanitizePath($requestedPath);

        $payload['ref'] = $ref;
        $payload['path'] = $path;
        $payload['breadcrumbs'] = $this->buildBreadcrumbs($path);

        if (empty($repo['public_browser_enabled'])) {
            if (!empty($repo['public_download_enabled'])) {
                return array_replace($payload, ['mode' => 'downloads']);
            }

            return array_replace($payload, ['mode' => 'metadata']);
        }

        $objectType = $path === '' ? 'tree' : $this->objectTypeAtPath($repoPath, $ref, $path);
        if ($objectType === 'blob') {
            return array_replace($payload, [
                'mode' => 'file',
                'file' => $this->buildFilePreview($repoPath, $ref, $path),
            ]);
        }

        if ($objectType !== 'tree') {
            throw new RuntimeException('The requested repository path was not found.');
        }

        $entries = $this->listTreeEntries($repoPath, $ref, $path);
        $readme = null;
        if ($includeReadme) {
            $readme = $this->buildReadmePreview($repoPath, $ref, $path, $entries);
        }

        return array_replace($payload, [
            'mode' => 'tree',
            'entries' => $entries,
            'readme' => $readme,
        ]);
    }

    /**
     * Streams one public repository file into a temporary file for raw/download responses.
     *
     * @param string $slug Repository slug.
     * @param string|null $requestedRef Optional branch/ref request.
     * @param string $requestedPath Repo-relative file path.
     * @return array<string, mixed> Temp-file response payload.
     */
    public function readPublicFile(string $slug, ?string $requestedRef, string $requestedPath): array
    {
        $repo = $this->getRequiredRepo($slug);
        if (empty($repo['public_download_enabled'])) {
            throw new RuntimeException('This repository does not expose public file downloads.');
        }

        $repoPath = (string) ($repo['repository_path'] ?? '');
        if (!$this->isValidBareRepoPath($repoPath)) {
            throw new RuntimeException('This repository has not been synced yet.');
        }

        $ref = $this->resolvePreferredRef($repo, $requestedRef, $repoPath);
        $path = $this->sanitizePath($requestedPath);
        if ($path === '' || $this->objectTypeAtPath($repoPath, $ref, $path) !== 'blob') {
            throw new RuntimeException('The requested repository file was not found.');
        }

        $tempRoot = $this->projectRoot . '/.tmp';
        if (!is_dir($tempRoot) && !@mkdir($tempRoot, 0775, true) && !is_dir($tempRoot)) {
            throw new RuntimeException('Failed to create Raven-local temporary directory.');
        }

        $tempPath = tempnam($tempRoot, 'rvn_repo_file_');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to allocate a temporary file for repository output.');
        }

        $this->runGitOutputToFile(['show', $ref . ':' . $path], $repoPath, $tempPath);

        return [
            'temp_path' => $tempPath,
            'filename' => basename($path),
            'mime_type' => $this->detectMimeType($tempPath),
            'repo' => $repo,
            'ref' => $ref,
            'path' => $path,
        ];
    }

    /**
     * Loads one Markdown file from a mirrored repository reference.
     *
     * References use `repo://{slug}/{path}.md` and may select a branch with
     * `?ref={branch}` or `?branch={branch}`. Only text Markdown blobs within
     * the configured mirror are eligible, so the bare repository never becomes
     * a directly readable filesystem path.
     *
     * @param string $reference Repository-backed Markdown reference.
     * @return string|null Markdown contents, or null when the reference is invalid or unavailable.
     */
    public function load(string $reference): ?string
    {
        $parsed = $this->parseMarkdownReference($reference);
        if ($parsed === null) {
            return null;
        }

        $repo = $this->getRepo($parsed['slug']);
        if ($repo === null) {
            return null;
        }

        $repoPath = (string) ($repo['repository_path'] ?? '');
        if (!$this->isValidBareRepoPath($repoPath)) {
            return null;
        }

        try {
            $ref = $this->resolvePreferredRef($repo, $parsed['ref'], $repoPath);
            $path = $parsed['path'];
            if ($this->objectTypeAtPath($repoPath, $ref, $path) !== 'blob') {
                return null;
            }

            $size = $this->objectSizeAtPath($repoPath, $ref, $path);
            if ($size === null || $size > self::TEXT_PREVIEW_BYTES) {
                return null;
            }

            $content = $this->runGitOutput(['show', $ref . ':' . $path], $repoPath);
        } catch (\Throwable) {
            return null;
        }

        return $this->isLikelyText($content) ? $content : null;
    }

    /**
     * Builds one archive export payload for a public repository ref.
     *
     * @param string $slug Repository slug.
     * @param string|null $requestedRef Optional branch/ref request.
     * @param string $format Archive format (`zip` or `tar`).
     * @return array<string, mixed> Temp-file archive payload.
     */
    public function buildArchive(string $slug, ?string $requestedRef, string $format = 'zip'): array
    {
        $repo = $this->getRequiredRepo($slug);
        if (empty($repo['public_download_enabled'])) {
            throw new RuntimeException('This repository does not expose public archives.');
        }

        $repoPath = (string) ($repo['repository_path'] ?? '');
        if (!$this->isValidBareRepoPath($repoPath)) {
            throw new RuntimeException('This repository has not been synced yet.');
        }

        $resolvedFormat = strtolower(trim($format));
        if (!in_array($resolvedFormat, ['zip', 'tar'], true)) {
            $resolvedFormat = 'zip';
        }

        $ref = $this->resolvePreferredRef($repo, $requestedRef, $repoPath);
        $tempRoot = $this->projectRoot . '/.tmp';
        if (!is_dir($tempRoot) && !@mkdir($tempRoot, 0775, true) && !is_dir($tempRoot)) {
            throw new RuntimeException('Failed to create Raven-local temporary directory.');
        }

        $tempBase = tempnam($tempRoot, 'rvn_repo_archive_');
        if ($tempBase === false) {
            throw new RuntimeException('Failed to allocate a temporary file for archive export.');
        }

        @unlink($tempBase);
        $tempPath = $tempBase . '.' . $resolvedFormat;
        $this->git->mustRun([
            'archive',
            '--format=' . $resolvedFormat,
            '--output=' . $tempPath,
            $ref,
        ], $repoPath);

        return [
            'temp_path' => $tempPath,
            'filename' => $this->archiveFilename((string) ($repo['slug'] ?? 'repo'), $ref, $resolvedFormat),
            'mime_type' => $resolvedFormat === 'zip' ? 'application/zip' : 'application/x-tar',
            'repo' => $repo,
            'ref' => $ref,
            'format' => $resolvedFormat,
        ];
    }

    /**
     * Sanitizes a repo-relative path passed through query parameters.
     *
     * @param string $path Raw path value from input or query params.
     * @return string Safe repo-relative path, or an empty string for root/invalid input.
     */
    public function sanitizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0")) {
            return '';
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            if (str_contains($segment, ':') || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                return '';
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Returns the absolute base URL for the current Raven install.
     *
     * @param void
     * @return string Site base URL including mount path when installed in a subdirectory.
     */
    public function baseUrl(): string
    {
        $configuredProtocol = trim((string) $this->config->get('site.protocol', 'https'));
        $configuredProtocol = in_array($configuredProtocol, ['http', 'https'], true) ? $configuredProtocol : 'https';
        $configuredDomain = trim((string) $this->config->get('site.domain', 'localhost'));
        $requestHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            ? 'https'
            : $configuredProtocol;
        $host = $requestHost !== '' ? $requestHost : ($configuredDomain !== '' ? $configuredDomain : 'localhost');

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        if ($scriptName === '' || $scriptName[0] !== '/') {
            $scriptName = '/' . ltrim($scriptName, '/');
        }

        $mountBasePath = dirname($scriptName);
        if ($mountBasePath === '.' || $mountBasePath === '/' || $mountBasePath === '\\') {
            $mountBasePath = '';
        }

        return rtrim($scheme . '://' . $host . $mountBasePath, '/');
    }

    /**
     * Returns the public clone URL for one repository when its objects are exposed publicly.
     *
     * @param array<string, mixed> $repo Decorated repository row.
     * @return string Clone URL, or an empty string when cloning is not public.
     */
    public function cloneUrl(array $repo): string
    {
        if (empty($repo['public_object_access'])) {
            return '';
        }

        return $this->baseUrl() . $this->publicRepositoryWebPath((string) ($repo['slug'] ?? ''));
    }

    /**
     * Returns select-option labels for repo visibility.
     *
     * @param bool $includeSystem Whether to include `system` in the returned map.
     * @return array<string, string> Visibility options keyed by stored value.
     */
    public function visibilityOptions(bool $includeSystem = false): array
    {
        $options = [
            'private' => 'Private only',
            'public_meta_private_objects' => 'Public metadata, private objects',
            'public_browser' => 'Fully public read-only browser',
            'public_downloads' => 'Public downloads only',
        ];

        if ($includeSystem) {
            return ['system' => 'System Default'] + $options;
        }

        return $options;
    }

    /**
     * Returns select-option labels for repo update frequency.
     *
     * @param bool $includeSystem Whether to include `system` in the returned map.
     * @return array<string, string> Frequency options keyed by stored value.
     */
    public function frequencyOptions(bool $includeSystem = false): array
    {
        $options = [
            'hourly' => 'Hourly',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
        ];

        if ($includeSystem) {
            return ['system' => 'System Default'] + $options;
        }

        return $options;
    }

    /**
     * Returns select-option labels for per-repo auto-update overrides.
     *
     * @param bool $includeSystem Whether to include `system` in the returned map.
     * @return array<string, string> Auto-update options keyed by stored value.
     */
    public function autoUpdateOptions(bool $includeSystem = false): array
    {
        $options = [
            'on' => 'Enabled',
            'off' => 'Disabled',
        ];

        if ($includeSystem) {
            return ['system' => 'System Default'] + $options;
        }

        return $options;
    }

    /**
     * Returns select-option labels for repo storage mode.
     *
     * @param void
     * @return array<string, string> Storage-mode options keyed by stored value.
     */
    public function storageOptions(): array
    {
        return [
            'local' => 'Private local bucket',
            'public' => 'Public upload bucket',
        ];
    }

    /**
     * Returns checkbox labels for log event filtering.
     *
     * @param void
     * @return array<string, string> Log event labels keyed by stored event value.
     */
    public function logEventOptions(): array
    {
        return [
            'settings_updated' => 'Settings updated',
            'repo_saved' => 'Repo saved',
            'repo_deleted' => 'Repo deleted',
            'sync_started' => 'Sync started',
            'sync_succeeded' => 'Sync succeeded',
            'sync_failed' => 'Sync failed',
            'sync_skipped' => 'Sync skipped',
        ];
    }

    /**
     * Decorates one raw registry row with derived runtime fields.
     *
     * @param array<string, mixed> $repo Raw registry row.
     * @return array<string, mixed> Decorated runtime row.
     */
    public function decorateRepo(array $repo): array
    {
        $settings = $this->settings();
        $slug = $this->normalizeSlug((string) ($repo['slug'] ?? ''));
        $requestedVisibility = $this->normalizeVisibility((string) ($repo['visibility'] ?? 'system'));
        $effectiveVisibility = $this->resolveVisibilityValue($requestedVisibility);
        $effectiveStorage = $this->storageForVisibility($effectiveVisibility);
        $effectiveAutoUpdate = $this->resolveAutoUpdateValue((string) ($repo['auto_update'] ?? 'system'));
        $effectiveFrequency = $this->resolveFrequencyValue((string) ($repo['update_frequency'] ?? 'system'));

        $path = $effectiveStorage === 'public'
            ? $this->publicRepositoryPath($slug)
            : $this->localRepositoryPath($slug);

        $publicObjectAccess = in_array($effectiveVisibility, ['public_browser', 'public_downloads'], true)
            && $effectiveStorage === 'public';

        return $repo + [
            'slug' => $slug,
            'effective_visibility' => $effectiveVisibility,
            'effective_storage' => $effectiveStorage,
            'effective_auto_update' => $effectiveAutoUpdate,
            'effective_update_frequency' => $effectiveFrequency,
            'repository_path' => $path,
            'public_repository_web_path' => $publicObjectAccess ? $this->publicRepositoryWebPath($slug) : '',
            'public_repo_url' => $publicObjectAccess ? $this->cloneUrl($repo + ['slug' => $slug, 'public_object_access' => true]) : '',
            'is_public_listed' => $effectiveVisibility !== 'private',
            'public_object_access' => $publicObjectAccess,
            'public_browser_enabled' => $publicObjectAccess && $effectiveVisibility === 'public_browser',
            'public_download_enabled' => $publicObjectAccess,
            'visibility_label' => $this->visibilityOptions()[$effectiveVisibility] ?? ucfirst($effectiveVisibility),
            'storage_label' => $this->storageOptions()[$effectiveStorage] ?? ucfirst($effectiveStorage),
            'auto_update_label' => $effectiveAutoUpdate ? 'Enabled' : 'Disabled',
            'update_frequency_label' => $this->frequencyOptions()[$effectiveFrequency] ?? ucfirst($effectiveFrequency),
            'source_count' => is_array($repo['sources'] ?? null) ? count($repo['sources']) : 0,
            'scheduler_available' => $this->schedulerAvailable(),
            'scheduler_mode' => $this->schedulerMode(),
            'settings_defaults' => $settings,
        ];
    }

    /**
     * Returns one normalized repository row or throws when missing.
     *
     * @param string $slug Repository slug.
     * @return array<string, mixed> Decorated repository row.
     */
    private function getRequiredRepo(string $slug): array
    {
        $repo = $this->getRepo($slug);
        if ($repo === null) {
            throw new RuntimeException('Repository was not found.');
        }

        return $repo;
    }

    /**
     * @param array<string, mixed>|null $existing Raw existing row before save.
     * @param array<string, mixed> $saved Decorated saved row after save.
     * @return void Moves mirror storage when visibility changes between local/public modes.
     */
    private function migrateRepositoryStorageIfNeeded(?array $existing, array $saved): void
    {
        if ($existing === null) {
            return;
        }

        $from = $this->decorateRepo($existing);
        $fromPath = (string) ($from['repository_path'] ?? '');
        $toPath = (string) ($saved['repository_path'] ?? '');

        if ($fromPath === '' || $toPath === '' || $fromPath === $toPath || !is_dir($fromPath)) {
            return;
        }

        if (is_dir($toPath)) {
            throw new RuntimeException('Target repository storage already exists and could not be migrated safely.');
        }

        RepoStorageSupport::ensureParentDirectory($toPath . '/.gitkeep');
        if (!@rename($fromPath, $toPath)) {
            throw new RuntimeException('Repository storage could not be moved to its new bucket.');
        }
    }

    /**
     * @return array<int, array{label: string, url: string, branch: string}>
     */
    private function normalizeSourceRows(mixed $rawSources): array
    {
        if (!is_array($rawSources)) {
            return [];
        }

        $sources = [];
        foreach ($rawSources as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '' || str_contains($url, "\0") || preg_match('/\s/', $url) === 1) {
                continue;
            }

            $sources[] = [
                'label' => trim((string) ($entry['label'] ?? '')),
                'url' => $url,
                'branch' => $this->normalizeRefName((string) ($entry['branch'] ?? '')),
            ];

            if (count($sources) >= 8) {
                break;
            }
        }

        return $sources;
    }

    /**
     * @return void
     */
    private function syncMirrorFromSource(string $repoPath, string $url): void
    {
        if (!$this->isValidBareRepoPath($repoPath)) {
            if (is_dir($repoPath)) {
                $this->directoryTree->removeTree($repoPath);
            }

            RepoStorageSupport::ensureParentDirectory($repoPath . '/HEAD');
            $this->git->mustRun(['clone', '--mirror', $url, $repoPath]);
            return;
        }

        $remoteUrl = $this->git->run(['remote', 'get-url', 'origin'], $repoPath);
        if (!$remoteUrl['ok']) {
            $this->git->mustRun(['remote', 'add', 'origin', $url], $repoPath);
        } elseif (trim((string) ($remoteUrl['stdout'] ?? '')) !== $url) {
            $this->git->mustRun(['remote', 'set-url', 'origin', $url], $repoPath);
        }

        // Mirror fetch configuration keeps the bare repo suitable for `git clone` over dumb HTTP.
        $this->git->mustRun(['config', 'remote.origin.mirror', 'true'], $repoPath);
        $this->git->mustRun(['config', 'remote.origin.fetch', '+refs/*:refs/*'], $repoPath);
        $this->git->mustRun(['fetch', '--prune', 'origin'], $repoPath);
    }

    /**
     * Refreshes derived branch/special-file metadata after a successful sync.
     *
     * @param string $slug Repository slug.
     * @param string $repoPath Absolute mirror path.
     * @param string $attemptedAt Timestamp captured before sync work began.
     * @param string $sourceLabel Human-friendly label for the source that succeeded.
     * @param int $sourceIndex Zero-based source row index that produced the successful sync.
     * @return array<string, mixed> Decorated repository row after refresh.
     */
    private function refreshRepositoryMetadata(string $slug, string $repoPath, string $attemptedAt, string $sourceLabel, int $sourceIndex): array
    {
        $repo = $this->getRequiredRepo($slug);
        $branches = $this->listBranches($repoPath);
        $defaultBranch = $this->detectDefaultBranch($repoPath, $branches);
        $specialFiles = $defaultBranch !== '' ? $this->detectSpecialFiles($repoPath, $defaultBranch) : ['readme_path' => '', 'license_path' => ''];
        $headCommit = $defaultBranch !== '' ? $this->resolveCommitHash($repoPath, $defaultBranch) : '';

        $selectedPublicBranch = $this->normalizeRefName((string) ($repo['public_branch'] ?? ''));
        if ($selectedPublicBranch === '') {
            // First successful sync should lock the public default branch to the detected source default branch.
            $selectedPublicBranch = $defaultBranch;
        }

        $sources = is_array($repo['sources'] ?? null) ? $repo['sources'] : [];
        if (isset($sources[$sourceIndex]) && is_array($sources[$sourceIndex])) {
            $savedSourceBranch = $this->normalizeRefName((string) ($sources[$sourceIndex]['branch'] ?? ''));
            if ($savedSourceBranch === '' && $defaultBranch !== '') {
                // First import sync should stamp the winning source row with the detected default branch.
                $sources[$sourceIndex]['branch'] = $defaultBranch;
            }
        }

        $saved = $this->registryStore->put([
            'slug' => $slug,
            'sources' => $sources,
            'default_branch' => $defaultBranch,
            'public_branch' => $selectedPublicBranch,
            'branch_cache' => $branches,
            'readme_path' => $specialFiles['readme_path'],
            'license_path' => $specialFiles['license_path'],
            'last_attempted_sync_at' => $attemptedAt,
            'last_successful_sync_at' => gmdate('c'),
            'last_error' => '',
            'last_error_at' => null,
            'last_sync_summary' => 'Synced successfully from ' . $sourceLabel . '.',
            'last_synced_head' => $headCommit,
            'disk_usage_bytes' => $this->directorySize($repoPath),
        ]);

        return $this->decorateRepo($saved);
    }

    /**
     * @return array<int, string>
     */
    private function listBranches(string $repoPath): array
    {
        $result = $this->git->run(['for-each-ref', '--format=%(refname:short)', 'refs/heads'], $repoPath);
        if (!$result['ok']) {
            return [];
        }

        $branches = preg_split('/\r?\n/', (string) ($result['stdout'] ?? '')) ?: [];
        $branches = array_values(array_filter(array_map(
            fn (mixed $branch): string => $this->normalizeRefName((string) $branch),
            $branches
        ), static fn (string $branch): bool => $branch !== ''));

        natcasesort($branches);
        return array_values($branches);
    }

    /**
     * @param array<int, string> $branches
     * @return string
     */
    private function detectDefaultBranch(string $repoPath, array $branches): string
    {
        $result = $this->git->run(['symbolic-ref', '--short', 'HEAD'], $repoPath);
        $branch = $result['ok'] ? $this->normalizeRefName((string) ($result['stdout'] ?? '')) : '';
        if ($branch !== '') {
            return $branch;
        }

        return $branches[0] ?? '';
    }

    /**
     * @return array{readme_path: string, license_path: string}
     */
    private function detectSpecialFiles(string $repoPath, string $ref): array
    {
        $entries = $this->listTreeEntries($repoPath, $ref, '');
        $readmePath = '';
        $licensePath = '';

        foreach ($entries as $entry) {
            $name = strtoupper((string) ($entry['name'] ?? ''));
            $path = (string) ($entry['path'] ?? '');
            if ($readmePath === '' && in_array($name, ['README', 'README.MD', 'README.TXT'], true)) {
                $readmePath = $path;
            }
            if ($licensePath === '' && str_starts_with($name, 'LICENSE')) {
                $licensePath = $path;
            }
        }

        return [
            'readme_path' => $readmePath,
            'license_path' => $licensePath,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listTreeEntries(string $repoPath, string $ref, string $path): array
    {
        $target = $path === '' ? $ref : ($ref . ':' . $path);
        $result = $this->git->run(['ls-tree', '-z', '-l', $target], $repoPath);
        if (!$result['ok']) {
            return [];
        }

        $entries = [];
        $rows = explode("\0", (string) ($result['stdout'] ?? ''));
        foreach ($rows as $row) {
            if ($row === '' || !str_contains($row, "\t")) {
                continue;
            }

            [$meta, $name] = explode("\t", $row, 2);
            $parts = preg_split('/\s+/', trim($meta)) ?: [];
            if (count($parts) < 4) {
                continue;
            }

            $entryType = strtolower((string) ($parts[1] ?? 'blob'));
            $entryPath = $path === '' ? $name : ($path . '/' . $name);
            $entries[] = [
                'mode' => (string) ($parts[0] ?? ''),
                'type' => $entryType,
                'hash' => (string) ($parts[2] ?? ''),
                'size' => ($parts[3] ?? '-') === '-' ? null : max(0, (int) ($parts[3] ?? 0)),
                'name' => $name,
                'path' => $entryPath,
                'is_dir' => $entryType === 'tree',
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            if ((bool) ($left['is_dir'] ?? false) !== (bool) ($right['is_dir'] ?? false)) {
                return !empty($left['is_dir']) ? -1 : 1;
            }

            return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $entries;
    }

    /**
     * Returns one file preview payload for inline public rendering.
     *
     * @param string $repoPath Absolute mirror path.
     * @param string $ref Resolved branch/ref.
     * @param string $path Repo-relative file path.
     * @return array<string, mixed> File preview payload.
     */
    private function buildFilePreview(string $repoPath, string $ref, string $path): array
    {
        $size = $this->objectSizeAtPath($repoPath, $ref, $path);
        $previewable = $size !== null && $size <= self::TEXT_PREVIEW_BYTES;
        $content = '';
        $isBinary = false;

        if ($previewable) {
            $content = $this->runGitOutput(['show', $ref . ':' . $path], $repoPath);
            $isBinary = !$this->isLikelyText($content);
            if ($isBinary) {
                $content = '';
            }
        }

        return [
            'path' => $path,
            'name' => basename($path),
            'size' => $size,
            'previewable' => $previewable && !$isBinary,
            'is_binary' => $isBinary,
            'content' => $content,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function buildReadmePreview(string $repoPath, string $ref, string $path, array $entries): ?array
    {
        $readmePath = '';
        foreach ($entries as $entry) {
            $name = strtoupper((string) ($entry['name'] ?? ''));
            if (!in_array($name, ['README', 'README.MD', 'README.TXT'], true)) {
                continue;
            }

            $readmePath = (string) ($entry['path'] ?? '');
            break;
        }

        if ($readmePath === '') {
            return null;
        }

        $preview = $this->buildFilePreview($repoPath, $ref, $readmePath);
        return $preview['previewable'] ? $preview : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildBreadcrumbs(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $breadcrumbs = [];
        $segments = explode('/', $path);
        $running = [];
        foreach ($segments as $segment) {
            $running[] = $segment;
            $breadcrumbs[] = [
                'label' => $segment,
                'path' => implode('/', $running),
            ];
        }

        return $breadcrumbs;
    }

    /**
     * @return string
     */
    private function objectTypeAtPath(string $repoPath, string $ref, string $path): string
    {
        $result = $this->git->run(['cat-file', '-t', $ref . ':' . $path], $repoPath);
        return $result['ok'] ? strtolower(trim((string) ($result['stdout'] ?? ''))) : '';
    }

    /**
     * @return int|null
     */
    private function objectSizeAtPath(string $repoPath, string $ref, string $path): ?int
    {
        $result = $this->git->run(['cat-file', '-s', $ref . ':' . $path], $repoPath);
        if (!$result['ok']) {
            return null;
        }

        return max(0, (int) ($result['stdout'] ?? 0));
    }

    /**
     * @return string
     */
    private function resolveCommitHash(string $repoPath, string $ref): string
    {
        $result = $this->git->run(['rev-parse', '--verify', $ref . '^{commit}'], $repoPath);
        return $result['ok'] ? trim((string) ($result['stdout'] ?? '')) : '';
    }

    /**
     * @param array<string, mixed> $repo Decorated repository row.
     * @return string
     */
    private function resolvePreferredRef(array $repo, ?string $requestedRef, string $repoPath): string
    {
        $candidates = [];
        foreach ([$requestedRef, $repo['public_branch'] ?? '', $repo['default_branch'] ?? ''] as $candidate) {
            $normalized = $this->normalizeRefName((string) $candidate);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }
        }

        $branches = is_array($repo['branch_cache'] ?? null) ? $repo['branch_cache'] : [];
        foreach ($branches as $branch) {
            $normalized = $this->normalizeRefName((string) $branch);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($this->resolveCommitHash($repoPath, $candidate) !== '') {
                return $candidate;
            }
        }

        throw new RuntimeException('No readable branch could be resolved for this repository.');
    }

    /**
     * @return void
     */
    private function log(string $event, string $level, string $message, ?string $slug = null, array $context = []): void
    {
        $settings = $this->settings();
        $events = is_array($settings['log_events'] ?? null) ? $settings['log_events'] : [];
        if (array_key_exists($event, $events) && empty($events[$event])) {
            return;
        }

        $this->logStore->append($event, $level, $message, $slug, $context);
        $this->logStore->pruneOlderThan((int) ($settings['log_prune_days'] ?? 30));
    }

    /**
     * @return string
     */
    private function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $value) === 1 ? $value : '';
    }

    /**
     * Parses and validates one repository-backed Markdown reference.
     *
     * @param string $reference Raw URI-like reference from a page body block.
     * @return array{slug: string, path: string, ref: string|null}|null Normalized reference, or null when invalid.
     */
    private function parseMarkdownReference(string $reference): ?array
    {
        $reference = trim($reference);
        if (preg_match('/^repo:\/\//i', $reference) !== 1) {
            return null;
        }

        $parts = parse_url($reference);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'repo') {
            return null;
        }

        // Userinfo and ports have no place in an internal repository reference.
        if (
            array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
        ) {
            return null;
        }

        $slug = $this->normalizeSlug((string) ($parts['host'] ?? ''));
        $path = $this->sanitizePath(rawurldecode((string) ($parts['path'] ?? '')));
        if ($slug === '' || $path === '' || preg_match('/\.(?:md|markdown)$/i', $path) !== 1) {
            return null;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $requestedRef = $query['ref'] ?? ($query['branch'] ?? null);
        if (!is_scalar($requestedRef) && $requestedRef !== null) {
            return null;
        }

        $ref = $requestedRef === null ? null : $this->normalizeRefName((string) $requestedRef);
        if ($requestedRef !== null && $ref === '') {
            return null;
        }

        return [
            'slug' => $slug,
            'path' => $path,
            'ref' => $ref !== '' ? $ref : null,
        ];
    }

    /**
     * @return string
     */
    private function normalizeVisibility(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, $this->visibilityOptions(true)) ? $value : 'system';
    }

    /**
     * @return string
     */
    private function normalizeStorage(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, $this->storageOptions()) ? $value : 'local';
    }

    /**
     * @return string
     */
    private function normalizeAutoUpdate(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, $this->autoUpdateOptions(true)) ? $value : 'system';
    }

    /**
     * @return string
     */
    private function normalizeFrequency(string $value, bool $includeSystem): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, $this->frequencyOptions($includeSystem))
            ? $value
            : ($includeSystem ? 'system' : 'daily');
    }

    /**
     * @return string
     */
    private function normalizeRefName(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9._\/-]{1,255}$/', $value) === 1 ? $value : '';
    }

    /**
     * @return string
     */
    private function resolveVisibilityValue(string $value): string
    {
        if ($value !== 'system') {
            return $value;
        }

        $defaults = $this->settings();
        $fallback = strtolower(trim((string) ($defaults['default_visibility'] ?? 'private')));
        return array_key_exists($fallback, $this->visibilityOptions()) ? $fallback : 'private';
    }

    /**
     * @return bool
     */
    private function resolveAutoUpdateValue(string $value): bool
    {
        if ($value === 'on') {
            return true;
        }
        if ($value === 'off') {
            return false;
        }

        return !empty($this->settings()['auto_update_enabled']);
    }

    /**
     * @return string
     */
    private function resolveFrequencyValue(string $value): string
    {
        if ($value !== 'system') {
            return $this->normalizeFrequency($value, false);
        }

        $defaults = $this->settings();
        return $this->normalizeFrequency((string) ($defaults['update_frequency'] ?? 'daily'), false);
    }

    /**
     * @return string
     */
    private function storageForVisibility(string $effectiveVisibility): string
    {
        return in_array($effectiveVisibility, ['public_browser', 'public_downloads'], true)
            ? 'public'
            : 'local';
    }

    /**
     * @return string
     */
    private function localRepositoryPath(string $slug): string
    {
        return $this->localRoot . '/repositories/' . $slug . '.git';
    }

    /**
     * @return string
     */
    private function publicRepositoryPath(string $slug): string
    {
        return $this->publicRoot . '/repositories/' . $slug . '.git';
    }

    /**
     * @return string
     */
    private function publicRepositoryWebPath(string $slug): string
    {
        return '/uploads/ext/repo/repositories/' . rawurlencode($slug) . '.git';
    }

    /**
     * @return bool
     */
    private function isValidBareRepoPath(string $path): bool
    {
        return $path !== ''
            && is_dir($path)
            && is_file($path . '/HEAD')
            && is_dir($path . '/objects');
    }

    /**
     * Executes one Git command and returns raw stdout without trimming whitespace.
     *
     * @param array<int, string> $arguments Git argument vector.
     * @param string|null $cwd Optional working directory.
     * @return string Raw stdout bytes.
     */
    private function runGitOutput(array $arguments, ?string $cwd = null): string
    {
        // Repository mirror workflows do not use Git hooks; disable them even
        // for the streaming helpers that bypass the shared Git value runner.
        $command = array_merge(['git', '-c', 'core.hooksPath=/dev/null'], array_values($arguments));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $cwd,
            [
                'GIT_TERMINAL_PROMPT' => '0',
                'GCM_INTERACTIVE' => 'Never',
            ]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start git output command.');
        }

        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $stdout = isset($pipes[1]) && is_resource($pipes[1])
                ? (string) stream_get_contents($pipes[1])
                : '';
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }

            $stderr = isset($pipes[2]) && is_resource($pipes[2])
                ? (string) stream_get_contents($pipes[2])
                : '';
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
        } finally {
            $exitCode = proc_close($process);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException($this->sanitizeErrorMessage($stderr !== '' ? $stderr : 'Git command failed.'));
        }

        return $stdout;
    }

    /**
     * Executes one Git command and writes stdout directly to a target file.
     *
     * @param array<int, string> $arguments Git argument vector.
     * @param string $cwd Working directory for the git command.
     * @param string $target Absolute temp file path.
     * @return void Target file receives the command stdout.
     */
    private function runGitOutputToFile(array $arguments, string $cwd, string $target): void
    {
        // Keep file-output Git commands under the same no-hooks policy.
        $command = array_merge(['git', '-c', 'core.hooksPath=/dev/null'], array_values($arguments));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $target, 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $cwd,
            [
                'GIT_TERMINAL_PROMPT' => '0',
                'GCM_INTERACTIVE' => 'Never',
            ]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start git file-output command.');
        }

        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $stderr = isset($pipes[2]) && is_resource($pipes[2])
                ? (string) stream_get_contents($pipes[2])
                : '';
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
        } finally {
            $exitCode = proc_close($process);
        }

        if ($exitCode !== 0) {
            @unlink($target);
            throw new RuntimeException($this->sanitizeErrorMessage($stderr !== '' ? $stderr : 'Git command failed.'));
        }
    }

    /**
     * @return int
     */
    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;

        // Repo mirrors are nested trees; iterator-based sizing avoids shelling out to `du`.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += max(0, (int) $item->getSize());
            }
        }

        return $size;
    }

    /**
     * @return string
     */
    private function archiveFilename(string $slug, string $ref, string $format): string
    {
        $safeRef = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ref) ?: 'snapshot';
        return $slug . '-' . trim($safeRef, '-') . '.' . $format;
    }

    /**
     * @return string
     */
    private function detectMimeType(string $path): string
    {
        if (!is_file($path)) {
            return 'application/octet-stream';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * @return bool
     */
    private function isLikelyText(string $content): bool
    {
        if ($content === '') {
            return true;
        }

        if (str_contains($content, "\0")) {
            return false;
        }

        $sample = substr($content, 0, 8192);
        $length = strlen($sample);
        if ($length === 0) {
            return true;
        }

        $controlCount = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample) ?: 0;
        return ($controlCount / $length) < 0.05;
    }

    /**
     * @return string
     */
    private function sanitizeErrorMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Operation failed.';
        }

        // Git errors can echo tokenized HTTPS remotes; redact inline credentials before storing them.
        $message = preg_replace('/([a-z]+:\/\/)[^@\s]+@/i', '$1***@', $message) ?? $message;
        return $message;
    }
}
