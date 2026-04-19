<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Update.php
 * Compares, plans, and applies package updates from a git source.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use FilesystemIterator;
use Raven\Core\Database\Schema\SchemaEnsureStateStore;
use Raven\Lib\Archive\Types\Git;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Compares, plans, and applies package updates from one git source.
 *
 * Fetches remote source state, diffs it against the managed local tree, builds
 * a file-level action plan (create/update/delete/skip), and can apply that plan
 * in place — keeping custom themes, extensions, and .gitignore-protected paths
 * untouched throughout the process.
 */
final class Update
{
    private string $root;
    private Git $git;
    /** @var array<int, string> */
    private array $stockThemeSlugs;
    /** @var array<int, string> */
    private array $stockExtensionDirectories;

    /**
     * @param string $root Absolute Raven project root path.
     * @param Git $git Git command runner instance.
     * @param array<int, string> $stockThemeSlugs Slugs of stock themes to protect from deletion.
     * @param array<int, string> $stockExtensionDirectories Directory names of stock extensions to protect.
     */
    public function __construct(
        string $root,
        Git $git,
        array $stockThemeSlugs,
        array $stockExtensionDirectories
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->git = $git;
        $this->stockThemeSlugs = array_values(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $stockThemeSlugs
        )));
        $this->stockExtensionDirectories = array_values(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $stockExtensionDirectories
        )));
    }

    /**
     * Compares the local revision against the remote source and returns state info.
     *
     * @param array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * } $source Resolved update source descriptor.
     * @return array<string, mixed> Comparison result with local/remote state and comparison details.
     */
    public function compare(array $source): array
    {
        try {
            $local = $this->localRepositoryState();
            $remote = $this->remoteRepositoryState($source);

            return [
                'ok' => true,
                'operation' => 'check',
                'message' => 'Checked for updates.',
                'source' => $source,
                'local' => $local,
                'remote' => $remote,
                'comparison' => $this->compareRevisionHeads($local, $remote),
                'actions' => [],
                'summary' => $this->emptySummary(),
            ];
        } catch (RuntimeException $exception) {
            return $this->errorResult('check', $source, $exception->getMessage());
        }
    }

    /**
     * Performs a dry run against the remote source and returns a planned action list.
     *
     * @param array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * } $source Resolved update source descriptor.
     * @param bool $allowOverwrite Whether to suppress the blocked-count warning when local files differ.
     * @return array<string, mixed> Dry-run result with planned actions and summary.
     */
    public function dryRun(array $source, bool $allowOverwrite = false): array
    {
        try {
            $workspace = $this->prepareWorkspace($source, true);
            try {
                $plan = $this->buildPlan($workspace, true);
                $message = $plan['summary']['blocked_count'] > 0 && !$allowOverwrite
                    ? 'Dry run found local managed-file changes that would require overwrite.'
                    : 'Dry run complete.';

                return [
                    'ok' => true,
                    'operation' => 'dry_run',
                    'message' => $message,
                    'source' => $source,
                    'local' => $workspace['local'],
                    'remote' => $workspace['remote'],
                    'comparison' => $workspace['comparison'],
                    'actions' => $plan['actions'],
                    'summary' => $plan['summary'],
                ];
            } finally {
                $this->deleteDirectoryRecursively((string) ($workspace['temp_dir'] ?? ''));
            }
        } catch (RuntimeException $exception) {
            return $this->errorResult('dry_run', $source, $exception->getMessage());
        }
    }

    /**
     * Applies the update plan from the remote source to the local tree.
     *
     * @param array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * } $source Resolved update source descriptor.
     * @param bool $allowOverwrite Whether to allow overwriting locally modified managed files.
     * @return array<string, mixed> Update result with applied action count and final state.
     */
    public function update(array $source, bool $allowOverwrite = false): array
    {
        try {
            $workspace = $this->prepareWorkspace($source, true);
            try {
                $plan = $this->buildPlan($workspace, true);

                if ($plan['summary']['blocked_count'] > 0 && !$allowOverwrite) {
                    return [
                        'ok' => false,
                        'operation' => 'update_now',
                        'message' => 'Update blocked by local managed-file changes. Run Dry Run or enable overwrite override.',
                        'source' => $source,
                        'local' => $workspace['local'],
                        'remote' => $workspace['remote'],
                        'comparison' => $workspace['comparison'],
                        'actions' => $plan['actions'],
                        'summary' => $plan['summary'],
                    ];
                }

                $appliedCount = $this->applyPlan($plan['actions'], (string) $workspace['source_tree']);
                $this->syncLocalRepositoryToSource((string) $source['source_url'], (string) $workspace['remote']['branch']);
                if ($appliedCount > 0) {
                    $this->schemaEnsureStateStore()->invalidate();
                }
                $localState = $this->localRepositoryState();
                $summary = $plan['summary'];
                $summary['applied_count'] = $appliedCount;

                return [
                    'ok' => true,
                    'operation' => 'update_now',
                    'message' => $appliedCount > 0
                        ? 'Update applied to the local tree.'
                        : 'Local tree already matches the selected source.',
                    'source' => $source,
                    'local' => $localState,
                    'remote' => $workspace['remote'],
                    'comparison' => [
                        'state' => 'up_to_date',
                        'label' => 'Up To Date',
                        'local_ahead' => 0,
                        'remote_ahead' => 0,
                    ],
                    'actions' => $plan['actions'],
                    'summary' => $summary,
                ];
            } finally {
                $this->deleteDirectoryRecursively((string) ($workspace['temp_dir'] ?? ''));
            }
        } catch (RuntimeException $exception) {
            return $this->errorResult('update_now', $source, $exception->getMessage());
        }
    }

    /**
     * Builds a normalized error result payload for a failed update operation.
     *
     * @param string $operation Operation key that failed (`check`, `dry_run`, or `update_now`).
     * @param array<string, mixed> $source Update source descriptor at time of failure.
     * @param string $message Human-facing error message.
     * @return array<string, mixed> Normalized error result.
     */
    private function errorResult(string $operation, array $source, string $message): array
    {
        return [
            'ok' => false,
            'operation' => $operation,
            'message' => $message,
            'source' => $source,
            'local' => $this->emptyRevisionInfo(),
            'remote' => $this->emptyRevisionInfo(),
            'comparison' => [
                'state' => 'unknown',
                'label' => 'Unknown',
                'local_ahead' => 0,
                'remote_ahead' => 0,
            ],
            'actions' => [],
            'summary' => $this->emptySummary(),
        ];
    }

    /**
     * Clones the remote source into a temporary workspace and resolves comparison state.
     *
     * @param array{
     *   mode: string,
     *   github_repo: string,
     *   repo_url: string,
     *   source_url: string,
     *   source_label: string
     * } $source Resolved update source descriptor.
     * @param bool $withWorkTree Whether to check out a working tree for file-level diff.
     * @return array{
     *   local: array<string, mixed>,
     *   remote: array<string, mixed>,
     *   comparison: array<string, mixed>,
     *   temp_dir: string,
     *   source_tree?: string
     * } Workspace descriptor including local/remote state and comparison result.
     */
    private function prepareWorkspace(array $source, bool $withWorkTree): array
    {
        $local = $this->localRepositoryState();
        $remote = $this->remoteRepositoryState($source);
        if (!$withWorkTree) {
            return [
                'local' => $local,
                'remote' => $remote,
                'comparison' => $this->compareRevisionHeads($local, $remote),
                'temp_dir' => '',
            ];
        }

        $tempDir = $this->tempPath();

        try {
            $this->git->mustRun([
                'clone',
                '--quiet',
                '--depth',
                '1',
                '--branch',
                (string) $remote['branch'],
                '--single-branch',
                (string) $source['source_url'],
                $tempDir,
            ], $this->root);
            $sourceTree = $tempDir;

            $this->git->mustRun(['remote', 'add', 'local-check', $this->root], $tempDir);
            $this->git->mustRun([
                'fetch',
                '--quiet',
                '--depth',
                '1',
                'local-check',
                'HEAD:refs/remotes/local-check/local-head',
            ], $tempDir);

            $range = $this->git->mustRun([
                'rev-list',
                '--left-right',
                '--count',
                'refs/remotes/local-check/local-head...refs/remotes/' . ($withWorkTree ? 'origin' : 'source') . '/' . (string) $remote['branch'],
            ], $tempDir);

            $counts = preg_split('/\s+/', trim((string) $range['stdout'])) ?: ['0', '0'];
            $localAhead = (int) ($counts[0] ?? 0);
            $remoteAhead = (int) ($counts[1] ?? 0);

            $comparisonState = 'up_to_date';
            $comparisonLabel = 'Up To Date';
            if ($localAhead > 0 && $remoteAhead > 0) {
                $comparisonState = 'diverged';
                $comparisonLabel = 'Diverged';
            } elseif ($localAhead > 0) {
                $comparisonState = 'ahead';
                $comparisonLabel = 'Local Ahead';
            } elseif ($remoteAhead > 0) {
                $comparisonState = 'behind';
                $comparisonLabel = 'Update Available';
            }

            return [
                'local' => $local,
                'remote' => $remote,
                'comparison' => [
                    'state' => $comparisonState,
                    'label' => $comparisonLabel,
                    'local_ahead' => $localAhead,
                    'remote_ahead' => $remoteAhead,
                ],
                'temp_dir' => $tempDir,
                'source_tree' => $sourceTree,
            ];
        } catch (RuntimeException $exception) {
            $this->deleteDirectoryRecursively($tempDir);
            throw $exception;
        }
    }

    /**
     * Builds the file-level action plan by diffing source tree against managed local files.
     *
     * @param array<string, mixed> $workspace Workspace descriptor from prepareWorkspace().
     * @param bool $preserveWorkspace Whether to skip temp-dir cleanup after plan build.
     * @return array{
     *   actions: array<int, array<string, mixed>>,
     *   summary: array<string, int>
     * } Action list and summary counters.
     */
    private function buildPlan(array $workspace, bool $preserveWorkspace): array
    {
        $sourceTree = (string) ($workspace['source_tree'] ?? '');
        if ($sourceTree === '' || !is_dir($sourceTree)) {
            throw new RuntimeException('Temporary source checkout is unavailable.');
        }

        $sourceFiles = $this->collectFiles($sourceTree);
        $localFiles = $this->localManagedFiles();
        $customThemeRoots = $this->customProtectedRoots(
            $this->root . '/public/theme',
            'public/theme',
            $this->stockThemeSlugs
        );
        $customExtensionRoots = $this->customProtectedRoots(
            $this->root . '/private/ext',
            'private/ext',
            $this->stockExtensionDirectories
        );

        $pathUniverse = array_values(array_unique(array_merge(array_keys($sourceFiles), array_keys($localFiles))));
        $ignoredPaths = $this->ignoredPathsMap($pathUniverse);
        $extensionBinAliases = $this->extensionBinAliasesMap(
            $this->root . '/private/bin',
            'private/bin'
        );
        $dirtyPaths = $this->dirtyPathsMap();

        $actions = [];
        foreach ($sourceFiles as $relativePath => $sourcePath) {
            $protectedReason = $this->protectedPathReason($relativePath, $ignoredPaths, $customThemeRoots, $customExtensionRoots, $extensionBinAliases);
            $localPath = $localFiles[$relativePath] ?? null;
            $localModified = isset($dirtyPaths[$relativePath]);

            if ($protectedReason !== null) {
                $actions[] = $this->planAction($relativePath, 'skip', $protectedReason, false, false);
                continue;
            }

            if (!is_string($localPath)) {
                $actions[] = $this->planAction($relativePath, 'create', 'New file from source.', false, false);
                continue;
            }

            if ($this->filesMatch($sourcePath, $localPath)) {
                continue;
            }

            $actions[] = $this->planAction(
                $relativePath,
                'update',
                $localModified ? 'Will overwrite local managed-file changes.' : 'Will replace with newer source file.',
                $localModified,
                $localModified
            );
        }

        foreach ($localFiles as $relativePath => $localPath) {
            if (isset($sourceFiles[$relativePath])) {
                continue;
            }

            $protectedReason = $this->protectedPathReason($relativePath, $ignoredPaths, $customThemeRoots, $customExtensionRoots, $extensionBinAliases);
            $localModified = isset($dirtyPaths[$relativePath]);
            if ($protectedReason !== null) {
                $actions[] = $this->planAction($relativePath, 'skip', $protectedReason, false, false);
                continue;
            }

            $actions[] = $this->planAction(
                $relativePath,
                'delete',
                $localModified ? 'Will delete local managed-file changes absent from source.' : 'No longer present in source.',
                $localModified,
                $localModified
            );
        }

        usort($actions, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
        });

        $summary = $this->emptySummary();
        foreach ($actions as $action) {
            $operation = (string) ($action['operation'] ?? 'skip');
            if (isset($summary[$operation . '_count'])) {
                $summary[$operation . '_count']++;
            }
            if (!empty($action['local_modified'])) {
                $summary['overwrite_count']++;
            }
            if (!empty($action['blocked'])) {
                $summary['blocked_count']++;
            }
        }

        if (!$preserveWorkspace) {
            $this->deleteDirectoryRecursively((string) ($workspace['temp_dir'] ?? ''));
        }

        return [
            'actions' => $actions,
            'summary' => $summary,
        ];
    }

    /**
     * Applies the planned action list to the local tree by copying/deleting files.
     *
     * @param array<int, array<string, mixed>> $actions Planned actions from buildPlan().
     * @param string $sourceTree Absolute path to the checked-out source tree.
     * @return int Number of file operations applied.
     * @throws RuntimeException When any file operation fails.
     */
    private function applyPlan(array $actions, string $sourceTree): int
    {
        $appliedCount = 0;
        $deletedDirectories = [];

        foreach ($actions as $action) {
            $operation = (string) ($action['operation'] ?? '');
            if ($operation === 'skip') {
                continue;
            }

            $relativePath = (string) ($action['path'] ?? '');
            if ($relativePath === '') {
                continue;
            }

            $targetPath = $this->root . '/' . $relativePath;
            $sourcePath = $sourceTree . '/' . $relativePath;

            if ($operation === 'delete') {
                if (is_file($targetPath) && !unlink($targetPath)) {
                    throw new RuntimeException('Failed to delete ' . $relativePath . '.');
                }
                $deletedDirectories[] = str_replace('\\', '/', dirname($targetPath));
                $appliedCount++;
                continue;
            }

            if (!is_file($sourcePath)) {
                throw new RuntimeException('Source file missing for ' . $relativePath . '.');
            }

            if (is_dir($targetPath)) {
                throw new RuntimeException('Update path conflict: target directory exists for ' . $relativePath . '.');
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new RuntimeException('Failed to create directory for ' . $relativePath . '.');
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Failed to write ' . $relativePath . '.');
            }

            @chmod($targetPath, fileperms($sourcePath) & 0777);
            $appliedCount++;
        }

        rsort($deletedDirectories);
        $deletedDirectories = array_values(array_unique($deletedDirectories));
        foreach ($deletedDirectories as $directory) {
            $this->pruneEmptyDirectories($directory);
        }

        return $appliedCount;
    }

    /**
     * Reads local git repository state (branch, revision, timestamp).
     *
     * @return array<string, mixed> Local state with `branch`, `revision`, and `timestamp` keys.
     * @throws RuntimeException When the local install is not inside a git working tree.
     */
    private function localRepositoryState(): array
    {
        $insideWorkTree = $this->git->mustRun(['rev-parse', '--is-inside-work-tree'], $this->root);
        if (strtolower(trim((string) $insideWorkTree['stdout'])) !== 'true') {
            throw new RuntimeException('Local install is not inside a git working tree.');
        }

        $revision = $this->git->mustRun(['rev-parse', 'HEAD'], $this->root);
        $branch = $this->git->run(['branch', '--show-current'], $this->root);
        $branchName = trim((string) $branch['stdout']);
        if ($branchName === '') {
            $branchName = 'detached';
        }

        $timestamp = $this->git->run(['show', '-s', '--format=%cI', 'HEAD'], $this->root);

        return [
            'branch' => $branchName,
            'revision' => trim((string) $revision['stdout']),
            'timestamp' => trim((string) ($timestamp['stdout'] ?? '')),
        ];
    }

    /**
     * Fetches remote repository state (branch, revision, timestamp) via ls-remote.
     *
     * @param array<string, mixed> $source Resolved update source descriptor.
     * @return array<string, mixed> Remote state with `branch`, `revision`, and `timestamp` keys.
     * @throws RuntimeException When the remote source URL is missing or the remote HEAD cannot be resolved.
     */
    private function remoteRepositoryState(array $source): array
    {
        $sourceUrl = trim((string) ($source['source_url'] ?? ''));
        if ($sourceUrl === '') {
            throw new RuntimeException('Resolved update source URL is empty.');
        }

        $head = $this->git->mustRun(['ls-remote', '--symref', $sourceUrl, 'HEAD'], $this->root);
        $branchName = '';
        $revision = '';

        foreach (preg_split("/\r?\n/", (string) $head['stdout']) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^ref:\s+refs\/heads\/([^\s]+)\s+HEAD$/', $trimmed, $matches) === 1) {
                $branchName = (string) ($matches[1] ?? '');
                continue;
            }

            if (preg_match('/^([0-9a-f]{40})\s+HEAD$/i', $trimmed, $matches) === 1) {
                $revision = strtolower((string) ($matches[1] ?? ''));
            }
        }

        if ($branchName === '') {
            throw new RuntimeException('Failed to resolve source default branch.');
        }

        if ($revision === '') {
            throw new RuntimeException('Failed to resolve source revision.');
        }

        return [
            'branch' => $branchName,
            'revision' => $revision,
            'timestamp' => $this->remoteRevisionTimestamp($sourceUrl, $branchName),
        ];
    }

    /**
     * Fetches the commit timestamp for the remote HEAD ref via a shallow clone.
     *
     * @param string $sourceUrl Resolved remote source URL.
     * @param string $branchName Remote default branch name.
     * @return string ISO-8601 committer timestamp, or empty string on failure.
     */
    private function remoteRevisionTimestamp(string $sourceUrl, string $branchName): string
    {
        $tempDir = $this->tempPath();
        try {
            if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
                throw new RuntimeException('Failed to initialize temporary update metadata directory.');
            }

            $this->git->mustRun(['init', '--quiet'], $tempDir);
            $this->git->mustRun(['remote', 'add', 'source', $sourceUrl], $tempDir);
            $this->git->mustRun([
                'fetch',
                '--quiet',
                '--depth',
                '1',
                '--no-tags',
                'source',
                'refs/heads/' . $branchName . ':refs/remotes/source/' . $branchName,
            ], $tempDir);

            $timestamp = $this->git->run(['show', '-s', '--format=%cI', 'refs/remotes/source/' . $branchName], $tempDir);

            return trim((string) ($timestamp['stdout'] ?? ''));
        } finally {
            $this->deleteDirectoryRecursively($tempDir);
        }
    }

    /**
     * Compares local and remote revision hashes to determine update state.
     *
     * @param array<string, mixed> $local Local state from localRepositoryState().
     * @param array<string, mixed> $remote Remote state from remoteRepositoryState().
     * @return array<string, mixed> Comparison result with `state`, `label`, and ahead/behind counts.
     */
    private function compareRevisionHeads(array $local, array $remote): array
    {
        $localRevision = strtolower(trim((string) ($local['revision'] ?? '')));
        $remoteRevision = strtolower(trim((string) ($remote['revision'] ?? '')));

        if ($localRevision !== '' && $remoteRevision !== '' && $localRevision === $remoteRevision) {
            return [
                'state' => 'up_to_date',
                'label' => 'Up To Date',
                'local_ahead' => 0,
                'remote_ahead' => 0,
            ];
        }

        return [
            'state' => 'behind',
            'label' => 'Update Available',
            'local_ahead' => 0,
            'remote_ahead' => $remoteRevision !== '' ? 1 : 0,
        ];
    }

    /**
     * Builds a map of relative paths that are excluded by .gitignore rules.
     *
     * @param array<int, string> $paths Candidate relative paths to check.
     * @return array<string, bool> Map of ignored relative paths.
     * @throws RuntimeException When git check-ignore fails with a non-standard exit code.
     */
    private function ignoredPathsMap(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $stdin = implode("\n", $paths) . "\n";
        $result = $this->git->run(['check-ignore', '--no-index', '--stdin'], $this->root, $stdin);
        if (!$result['ok'] && (int) $result['exit_code'] !== 1) {
            throw new RuntimeException($result['stderr'] !== '' ? $result['stderr'] : 'Failed to evaluate .gitignore paths.');
        }

        $ignored = [];
        foreach (preg_split("/\r?\n/", (string) $result['stdout']) ?: [] as $path) {
            $normalized = $this->normalizeRelativePath($path);
            if ($normalized !== '') {
                $ignored[$normalized] = true;
            }
        }

        return $ignored;
    }

    /**
     * Builds a map of relative paths with uncommitted local changes.
     *
     * @return array<string, bool> Map of dirty relative paths.
     */
    private function dirtyPathsMap(): array
    {
        $result = $this->git->mustRun(['status', '--porcelain', '-z', '--untracked-files=all', '--ignored=no'], $this->root);
        $raw = (string) ($result['stdout'] ?? '');
        if ($raw === '') {
            return [];
        }

        $entries = explode("\0", $raw);
        $dirty = [];
        for ($index = 0; $index < count($entries); $index++) {
            $entry = (string) ($entries[$index] ?? '');
            if ($entry === '' || strlen($entry) < 4) {
                continue;
            }

            $status = substr($entry, 0, 2);
            $path = $this->normalizeRelativePath(substr($entry, 3));
            if ($path !== '') {
                $dirty[$path] = true;
            }

            if (str_contains($status, 'R') || str_contains($status, 'C')) {
                $index++;
                $renamedFrom = $this->normalizeRelativePath((string) ($entries[$index] ?? ''));
                if ($renamedFrom !== '') {
                    $dirty[$renamedFrom] = true;
                }
            }
        }

        return $dirty;
    }

    /**
     * Collects all files under a directory tree as a relative-path => absolute-path map.
     *
     * @param string $basePath Absolute directory root.
     * @param array<int, string> $excludePrefixes Relative path prefixes to exclude (e.g. `.git`).
     * @return array<string, string> Sorted map of relative path => absolute path.
     */
    private function collectFiles(string $basePath, array $excludePrefixes = ['.git']): array
    {
        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $normalizedExcludes = [];
        foreach ($excludePrefixes as $prefix) {
            $normalized = $this->normalizeRelativePath((string) $prefix);
            if ($normalized !== '') {
                $normalizedExcludes[] = $normalized;
            }
        }

        $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function ($current) use ($basePath, $normalizedExcludes): bool {
                if (!$current instanceof \SplFileInfo) {
                    return true;
                }

                $fullPath = str_replace('\\', '/', $current->getPathname());
                $relative = ltrim(substr($fullPath, strlen($basePath)), '/');
                $relative = $this->normalizeRelativePath($relative);
                if ($relative === '') {
                    return true;
                }

                foreach ($normalizedExcludes as $prefix) {
                    if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                        return false;
                    }
                }

                return true;
            }
        );
        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($fullPath, strlen($basePath)), '/');
            $relative = $this->normalizeRelativePath($relative);
            if ($relative === '') {
                continue;
            }

            $files[$relative] = $fullPath;
        }

        ksort($files);
        return $files;
    }

    /**
     * Lists all tracked and untracked managed files as a relative-path => absolute-path map.
     *
     * @return array<string, string> Sorted map of relative path => absolute path.
     */
    private function localManagedFiles(): array
    {
        $result = $this->git->mustRun(['ls-files', '-z', '--cached', '--others', '--exclude-standard'], $this->root);
        $raw = (string) ($result['stdout'] ?? '');
        if ($raw === '') {
            return [];
        }

        $files = [];
        foreach (explode("\0", $raw) as $path) {
            $relative = $this->normalizeRelativePath($path);
            if ($relative === '') {
                continue;
            }

            $fullPath = $this->root . '/' . $relative;
            if (!is_file($fullPath)) {
                continue;
            }

            $files[$relative] = $fullPath;
        }

        ksort($files);
        return $files;
    }

    /**
     * Returns relative root paths for directories that are NOT in the stock list.
     *
     * Used to identify custom themes and extensions that should be protected from
     * the updater's delete pass.
     *
     * @param string $absoluteBase Absolute directory to scan.
     * @param string $relativeBase Relative prefix used when building result paths.
     * @param array<int, string> $stockNames Lowercase stock entry names to exclude.
     * @return array<int, string> Sorted list of custom protected root paths.
     */
    private function customProtectedRoots(string $absoluteBase, string $relativeBase, array $stockNames): array
    {
        if (!is_dir($absoluteBase)) {
            return [];
        }

        $roots = [];
        $entries = scandir($absoluteBase) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $fullPath = $absoluteBase . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            if (in_array(strtolower($entry), $stockNames, true)) {
                continue;
            }

            $roots[] = $relativeBase . '/' . $entry;
        }

        sort($roots);
        return $roots;
    }

    /**
     * Returns a map of relative paths for symlinks present in private/bin/.
     *
     * Extension bin commands are symlinks created by ExtensionStorageProvisioner::ensureBinSymlinks().
     * Stock CLI scripts are regular files shipped in the source tree. Scanning for symlinks here
     * lets the updater distinguish between the two without any extension-registry coupling.
     *
     * @param string $absoluteBinDir Absolute path to the private/bin directory.
     * @param string $relativeBinDir Relative prefix to use when building result keys (e.g. "private/bin").
     * @return array<string, bool> Map of relative path => true for each symlink found.
     */
    private function extensionBinAliasesMap(string $absoluteBinDir, string $relativeBinDir): array
    {
        if (!is_dir($absoluteBinDir)) {
            return [];
        }

        $aliases = [];
        $entries = scandir($absoluteBinDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // Only symlinks are extension aliases; regular files are stock scripts.
            if (!is_link($absoluteBinDir . '/' . $entry)) {
                continue;
            }

            $relative = $this->normalizeRelativePath($relativeBinDir . '/' . $entry);
            if ($relative !== '') {
                $aliases[$relative] = true;
            }
        }

        return $aliases;
    }

    /**
     * Returns a protection reason string for a path, or null when it is safe to update.
     *
     * @param string $path Relative path to check.
     * @param array<string, bool> $ignoredPaths .gitignore-matched paths.
     * @param array<int, string> $customThemeRoots Custom theme root prefixes.
     * @param array<int, string> $customExtensionRoots Custom extension root prefixes.
     * @param array<string, bool> $extensionBinAliases Pre-computed map of extension bin symlink paths.
     * @return string|null Protection reason, or null when the path is safe to update.
     */
    private function protectedPathReason(
        string $path,
        array $ignoredPaths,
        array $customThemeRoots,
        array $customExtensionRoots,
        array $extensionBinAliases
    ): ?string {
        if (isset($ignoredPaths[$path])) {
            return 'Protected by .gitignore.';
        }

        foreach ($customThemeRoots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return 'Protected custom theme path.';
            }
        }

        foreach ($customExtensionRoots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return 'Protected custom extension path.';
            }
        }

        if (isset($extensionBinAliases[$path])) {
            return 'Protected extension bin alias.';
        }

        return null;
    }

    /**
     * Builds one action row for the plan.
     *
     * @param string $path Relative file path.
     * @param string $operation Action key (`create`, `update`, `delete`, or `skip`).
     * @param string $detail Human-facing reason for the action.
     * @param bool $localModified Whether the file has local uncommitted changes.
     * @param bool $blocked Whether this action is flagged as potentially destructive.
     * @return array<string, mixed> Action row.
     */
    private function planAction(
        string $path,
        string $operation,
        string $detail,
        bool $localModified,
        bool $blocked
    ): array {
        return [
            'path' => $path,
            'operation' => $operation,
            'detail' => $detail,
            'local_modified' => $localModified,
            'blocked' => $blocked,
        ];
    }

    /**
     * Returns true when two files have the same size and SHA-1 hash.
     *
     * @param string $leftPath Absolute path to the first file.
     * @param string $rightPath Absolute path to the second file.
     * @return bool True when the files are byte-for-byte identical.
     */
    private function filesMatch(string $leftPath, string $rightPath): bool
    {
        if (!is_file($leftPath) || !is_file($rightPath)) {
            return false;
        }

        if (filesize($leftPath) !== filesize($rightPath)) {
            return false;
        }

        return hash_file('sha1', $leftPath) === hash_file('sha1', $rightPath);
    }

    /**
     * Normalizes a file path to a forward-slash relative path.
     *
     * @param string $path Raw path string from git output or filesystem.
     * @return string Normalized relative path without leading slash.
     */
    private function normalizeRelativePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        return ltrim($normalized, '/');
    }

    /**
     * Creates and returns a unique temporary directory path under .tmp/update.
     *
     * @return string Absolute temporary directory path (not yet created on disk).
     * @throws RuntimeException When the update workspace root cannot be created or is not writable.
     */
    private function tempPath(): string
    {
        $updatesRoot = $this->root . '/.tmp/update';
        if (!is_dir($updatesRoot) && !mkdir($updatesRoot, 0775, true) && !is_dir($updatesRoot)) {
            throw new RuntimeException('Failed to initialize Raven update workspace at .tmp/update.');
        }

        if (!is_writable($updatesRoot)) {
            throw new RuntimeException('Raven update workspace .tmp/update is not writable.');
        }

        return rtrim(str_replace('\\', '/', $updatesRoot), '/') . '/raven-update-' . bin2hex(random_bytes(6));
    }

    /**
     * Walks upward from a deleted file's directory and removes now-empty ancestors.
     *
     * @param string $directory Absolute path to start the upward pruning walk from.
     * @return void
     */
    private function pruneEmptyDirectories(string $directory): void
    {
        $normalizedRoot = $this->root;
        $current = rtrim(str_replace('\\', '/', $directory), '/');
        while ($current !== '' && $current !== $normalizedRoot && str_starts_with($current, $normalizedRoot . '/')) {
            if (!is_dir($current)) {
                $current = dirname($current);
                $current = rtrim(str_replace('\\', '/', $current), '/');
                continue;
            }

            $entries = scandir($current);
            if (!is_array($entries) || count($entries) > 2) {
                break;
            }

            @rmdir($current);
            $current = dirname($current);
            $current = rtrim(str_replace('\\', '/', $current), '/');
        }
    }

    /**
     * Removes a directory and all of its contents recursively.
     *
     * @param string $directory Absolute path to the directory to remove.
     * @return void
     */
    private function deleteDirectoryRecursively(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        // CHILD_FIRST ensures files and subdirs are removed before their parent dirs.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * Advances the local git HEAD to match the applied source state.
     *
     * @param string $sourceUrl Resolved remote source URL.
     * @param string $branch Remote branch name to fetch and reset to.
     * @return void
     */
    private function syncLocalRepositoryToSource(string $sourceUrl, string $branch): void
    {
        $this->git->mustRun(['fetch', '--quiet', '--depth', '1', $sourceUrl, $branch], $this->root);
        $this->git->mustRun(['reset', '--mixed', '--quiet', 'FETCH_HEAD'], $this->root);
    }

    /**
     * Returns the schema ensure marker helper for the local install root.
     *
     * Updates can add, remove, or replace schema-related files, so the next
     * bootstrap needs a fresh ensure pass after any applied update action.
     *
     * @return SchemaEnsureStateStore Shared schema ensure marker helper.
     */
    private function schemaEnsureStateStore(): SchemaEnsureStateStore
    {
        return new SchemaEnsureStateStore($this->root);
    }

    /**
     * Returns an empty summary counter map.
     *
     * @return array<string, int> Zero-initialized summary counters.
     */
    private function emptySummary(): array
    {
        return [
            'create_count' => 0,
            'update_count' => 0,
            'delete_count' => 0,
            'skip_count' => 0,
            'overwrite_count' => 0,
            'blocked_count' => 0,
            'applied_count' => 0,
        ];
    }

    /**
     * Returns an empty revision info map.
     *
     * @return array<string, string> Zero-initialized revision info.
     */
    private function emptyRevisionInfo(): array
    {
        return [
            'branch' => '',
            'revision' => '',
            'timestamp' => '',
        ];
    }
}
