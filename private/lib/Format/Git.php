<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Git.php
 * Canonical Git command and repository handler for Raven core and extensions.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * Canonical Git handler for Raven core and extensions.
 *
 * This class is the single Git-facing library surface for Raven. It provides
 * both low-level `run()` / `mustRun()` command execution and higher-level clone,
 * fetch, repository-inspection, and worktree-export helpers so callers no
 * longer need a second command-runner wrapper alongside the archive type.
 *
 * All commands run via `proc_open` without shell interpolation.
 */
final class Git
{
    private string $binary;

    /**
     * @param string $binary Git binary path; defaults to `git`.
     */
    public function __construct(string $binary = 'git')
    {
        $this->binary = $binary !== '' ? $binary : 'git';
    }

    /**
     * Runs one git command without shell interpolation.
     *
     * @param array<int, string> $arguments Git CLI arguments excluding the binary.
     * @param string|null $cwd Optional working directory.
     * @param string|null $stdin Optional stdin payload.
     * @return array{
     *   ok: bool,
     *   exit_code: int,
     *   stdout: string,
     *   stderr: string,
     *   command: string
     * }
     * @throws RuntimeException When the git process cannot be started.
     */
    public function run(array $arguments, ?string $cwd = null, ?string $stdin = null): array
    {
        // Raven uses Git only for repository inspection, synchronization, and export;
        // repository hooks are not part of any supported workflow, so disable them
        // for every command before a remote or local repository can provide one.
        $command = array_merge([$this->binary, '-c', 'core.hooksPath=/dev/null'], array_values(array_map(
            static fn (string $argument): string => $argument,
            $arguments
        )));

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

        // Process must start successfully before pipe IO can proceed.
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start git command.');
        }

        $stdout = '';
        $stderr = '';

        // Collect stdin/stdout/stderr with guaranteed process close in finally.
        try {
            // Write optional stdin payload and close stdin pipe.
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                if ($stdin !== null && $stdin !== '') {
                    fwrite($pipes[0], $stdin);
                }
                fclose($pipes[0]);
            }

            // Read and close stdout pipe when available.
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout = stream_get_contents($pipes[1]) ?: '';
                fclose($pipes[1]);
            }

            // Read and close stderr pipe when available.
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr = stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[2]);
            }
        } finally {
            $exitCode = proc_close($process);
        }

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'command' => implode(' ', $command),
        ];
    }

    /**
     * Runs one git command and throws when the exit code is non-zero.
     *
     * @param array<int, string> $arguments Git CLI arguments excluding the binary.
     * @param string|null $cwd Optional working directory.
     * @param string|null $stdin Optional stdin payload.
     * @return array{
     *   ok: bool,
     *   exit_code: int,
     *   stdout: string,
     *   stderr: string,
     *   command: string
     * }
     * @throws RuntimeException When the command fails or the process cannot be started.
     */
    public function mustRun(array $arguments, ?string $cwd = null, ?string $stdin = null): array
    {
        $result = $this->run($arguments, $cwd, $stdin);
        // Non-zero git exits are promoted to exceptions with stderr context.
        if (!$result['ok']) {
            $message = $result['stderr'] !== '' ? $result['stderr'] : 'Git command failed.';
            throw new RuntimeException($message);
        }

        return $result;
    }

    /**
     * Returns true when the git binary is reachable and executable.
     *
     * @return bool True when git is available on this system.
     */
    public function isAvailable(): bool
    {
        $result = $this->run(['--version']);
        return $result['ok'];
    }

    /**
     * Clones a remote Git repository into a local target directory.
     *
     * By default performs a shallow clone (`--depth 1`) of the default or
     * specified branch. Pass `$depth = 0` for a full clone.
     *
     * @param string $url Remote repository URL.
     * @param string $targetDir Absolute clone target directory.
     * @param string|null $branch Optional branch to clone.
     * @param int $depth Shallow clone depth; `0` means full clone.
     * @return void
     * @throws RuntimeException When the clone fails.
     */
    public function clone(string $url, string $targetDir, ?string $branch = null, int $depth = 1): void
    {
        $arguments = ['clone', '--quiet'];

        // Apply shallow depth only when caller requested it.
        if ($depth > 0) {
            $arguments[] = '--depth';
            $arguments[] = (string) $depth;
        }

        // Apply branch pinning when caller supplied a branch name.
        if ($branch !== null && $branch !== '') {
            $arguments[] = '--branch';
            $arguments[] = $branch;
            $arguments[] = '--single-branch';
        }

        $arguments[] = $url;
        $arguments[] = $targetDir;

        $this->mustRun($arguments);
    }

    /**
     * Fetches refs from a remote into an existing local repository.
     *
     * @param string $repoDir Working directory of the local repository.
     * @param string $remote Remote name or URL.
     * @param string|null $ref Optional branch or refspec.
     * @param int $depth Optional shallow fetch depth; `0` means full history.
     * @return void
     * @throws RuntimeException When the fetch fails.
     */
    public function fetch(string $repoDir, string $remote = 'origin', ?string $ref = null, int $depth = 0): void
    {
        $arguments = ['fetch', '--quiet'];

        // Apply shallow depth only when caller requested it.
        if ($depth > 0) {
            $arguments[] = '--depth';
            $arguments[] = (string) $depth;
        }

        $arguments[] = $remote;

        // Append explicit ref/refspec when provided.
        if ($ref !== null && $ref !== '') {
            $arguments[] = $ref;
        }

        $this->mustRun($arguments, $repoDir);
    }

    /**
     * Adds a named remote to an existing local repository.
     *
     * @param string $repoDir Working directory of the local repository.
     * @param string $name Remote name to add.
     * @param string $url Remote URL.
     * @return void
     * @throws RuntimeException When the remote cannot be added.
     */
    public function addRemote(string $repoDir, string $name, string $url): void
    {
        $this->mustRun(['remote', 'add', $name, $url], $repoDir);
    }

    /**
     * Returns true when the given directory is inside a git working tree.
     *
     * @param string $dir Absolute path to inspect.
     * @return bool True when the directory is inside a repository.
     */
    public function isRepository(string $dir): bool
    {
        $result = $this->run(['rev-parse', '--is-inside-work-tree'], $dir);
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
        $result = $this->mustRun(['rev-parse', 'HEAD'], $repoDir);
        return trim((string) $result['stdout']);
    }

    /**
     * Returns the current branch name in a local repository.
     *
     * @param string $repoDir Working directory of the local repository.
     * @return string Current branch name, or `detached`.
     * @throws RuntimeException When the repository state cannot be resolved.
     */
    public function currentBranch(string $repoDir): string
    {
        $result = $this->run(['branch', '--show-current'], $repoDir);
        $branch = trim((string) ($result['stdout'] ?? ''));
        return $branch !== '' ? $branch : 'detached';
    }

    /**
     * Extracts the checked-out work tree of a repository into a target directory.
     *
     * @param string $repoDir Working directory of the source repository.
     * @param string $targetDir Absolute destination directory.
     * @return void
     * @throws RuntimeException When extraction fails.
     */
    public function extractTree(string $repoDir, string $targetDir): void
    {
        // Ensure extraction target directory exists before checkout-index.
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create worktree extraction target directory: ' . $targetDir);
        }

        $prefix = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';
        $this->mustRun(['checkout-index', '--all', '--prefix=' . $prefix], $repoDir);
    }

    /**
     * Initializes a new empty repository in the given directory.
     *
     * @param string $dir Absolute path for the new repository directory.
     * @return void
     * @throws RuntimeException When initialization fails.
     */
    public function init(string $dir): void
    {
        // Ensure repository directory exists before git init.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for git init: ' . $dir);
        }

        $this->mustRun(['init', '--quiet'], $dir);
    }
}
