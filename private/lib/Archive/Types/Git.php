<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Types/Git.php
 * High-level Git operations — clone, fetch, and repository inspection.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive\Types;

use Raven\Lib\Update\GitCommandRunner;
use RuntimeException;

/**
 * High-level Git handler for Raven core and extensions.
 *
 * Wraps `GitCommandRunner` to expose canonical clone, fetch, and repository
 * inspection operations without requiring callers to compose raw argument arrays.
 * The underlying `GitCommandRunner` is exposed via `runner()` for callers that
 * need low-level access (such as the Updater's diff/compare pipeline).
 *
 * All commands run via proc_open without shell interpolation (see GitCommandRunner).
 */
final class Git
{
    private GitCommandRunner $runner;

    /**
     * @param GitCommandRunner|null $runner Shared runner; a new default instance is created when null.
     * @param string                $binary Git binary path used when constructing the default runner.
     */
    public function __construct(?GitCommandRunner $runner = null, string $binary = 'git')
    {
        $this->runner = $runner ?? new GitCommandRunner($binary);
    }

    /**
     * Returns the underlying GitCommandRunner for low-level git operations.
     *
     * Use this when you need to run git commands not covered by the high-level
     * API — for example, `rev-list`, `check-ignore`, or `ls-remote`.
     *
     * @return GitCommandRunner The shared command runner instance.
     */
    public function runner(): GitCommandRunner
    {
        return $this->runner;
    }

    /**
     * Returns true when the git binary is reachable and executable.
     *
     * @return bool True when git is available on this system.
     */
    public function isAvailable(): bool
    {
        $result = $this->runner->run(['--version']);
        return $result['ok'];
    }

    /**
     * Clones a remote Git repository into a local target directory.
     *
     * By default performs a shallow clone (`--depth 1`) of the default or
     * specified branch. Pass `$depth = 0` for a full clone.
     *
     * @param string      $url       Remote repository URL (HTTPS or SSH).
     * @param string      $targetDir Absolute path where the clone should be created.
     * @param string|null $branch    Branch to clone; null clones the default branch.
     * @param int         $depth     Shallow clone depth; 0 means full clone.
     * @return void
     * @throws RuntimeException When the clone fails.
     */
    public function cloneRepository(string $url, string $targetDir, ?string $branch = null, int $depth = 1): void
    {
        $args = ['clone', '--quiet'];

        if ($depth > 0) {
            $args[] = '--depth';
            $args[] = (string) $depth;
        }

        if ($branch !== null && $branch !== '') {
            $args[] = '--branch';
            $args[] = $branch;
            $args[] = '--single-branch';
        }

        $args[] = $url;
        $args[] = $targetDir;

        $this->runner->mustRun($args);
    }

    /**
     * Fetches refs from a remote into an existing local repository.
     *
     * Optionally fetches a specific ref (branch name or refspec). Pass `$depth > 0`
     * for a shallow fetch to limit history depth.
     *
     * @param string      $repoDir Working directory of the existing local repository.
     * @param string      $remote  Remote name or URL to fetch from; defaults to `origin`.
     * @param string|null $ref     Branch name or refspec to fetch; null fetches the default.
     * @param int         $depth   Shallow fetch depth; 0 means full history.
     * @return void
     * @throws RuntimeException When the fetch fails.
     */
    public function fetch(string $repoDir, string $remote = 'origin', ?string $ref = null, int $depth = 0): void
    {
        $args = ['fetch', '--quiet'];

        if ($depth > 0) {
            $args[] = '--depth';
            $args[] = (string) $depth;
        }

        $args[] = $remote;

        if ($ref !== null && $ref !== '') {
            $args[] = $ref;
        }

        $this->runner->mustRun($args, $repoDir);
    }

    /**
     * Adds a named remote to an existing local repository.
     *
     * @param string $repoDir Working directory of the local repository.
     * @param string $name    Remote name to add (e.g. `origin`, `upstream`).
     * @param string $url     Remote URL.
     * @return void
     * @throws RuntimeException When the remote cannot be added.
     */
    public function addRemote(string $repoDir, string $name, string $url): void
    {
        $this->runner->mustRun(['remote', 'add', $name, $url], $repoDir);
    }

    /**
     * Returns true when the given directory is inside a git working tree.
     *
     * @param string $dir Absolute path to check.
     * @return bool True when the directory is inside a git repository.
     */
    public function isRepository(string $dir): bool
    {
        $result = $this->runner->run(['rev-parse', '--is-inside-work-tree'], $dir);
        return $result['ok'] && strtolower(trim((string) $result['stdout'])) === 'true';
    }

    /**
     * Returns the full commit SHA of the current HEAD in a local repository.
     *
     * @param string $repoDir Working directory of the local repository.
     * @return string Full 40-character commit SHA.
     * @throws RuntimeException When the repository state cannot be resolved.
     */
    public function currentRevision(string $repoDir): string
    {
        $result = $this->runner->mustRun(['rev-parse', 'HEAD'], $repoDir);
        return trim((string) $result['stdout']);
    }

    /**
     * Returns the current branch name in a local repository.
     *
     * Returns `'detached'` when HEAD is in a detached state (e.g. after a
     * shallow clone without a tracking branch).
     *
     * @param string $repoDir Working directory of the local repository.
     * @return string Current branch name, or `'detached'` when HEAD is detached.
     * @throws RuntimeException When the repository state cannot be resolved.
     */
    public function currentBranch(string $repoDir): string
    {
        $result = $this->runner->run(['branch', '--show-current'], $repoDir);
        $branch = trim((string) ($result['stdout'] ?? ''));
        return $branch !== '' ? $branch : 'detached';
    }

    /**
     * Extracts the checked-out working tree of a repository into a target directory.
     *
     * Copies all tracked, non-ignored files from the repository work tree into
     * `$targetDir` using `git checkout-index`. This is useful when you need a
     * clean copy of the repository contents without the `.git` directory.
     *
     * @param string $repoDir   Working directory of the source repository.
     * @param string $targetDir Absolute path of the destination directory (must exist).
     * @return void
     * @throws RuntimeException When extraction fails.
     */
    public function extractWorktree(string $repoDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create worktree extraction target directory: ' . $targetDir);
        }

        // `checkout-index --all --prefix=` copies the index into the target directory.
        // The prefix must end with a directory separator.
        $prefix = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';
        $this->runner->mustRun(['checkout-index', '--all', '--prefix=' . $prefix], $repoDir);
    }

    /**
     * Initializes a new empty repository in the given directory.
     *
     * Creates the directory if it does not yet exist.
     *
     * @param string $dir Absolute path for the new repository directory.
     * @return void
     * @throws RuntimeException When initialization fails.
     */
    public function init(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for git init: ' . $dir);
        }

        $this->runner->mustRun(['init', '--quiet'], $dir);
    }
}
