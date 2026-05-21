<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Zst.php
 * Zstandard single-file compression and decompression handler via the zstd binary.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * Zstandard single-file handler for Raven core and extensions.
 *
 * Zstandard (zstd) is a file-level compression format. PHP has no standard
 * native zstd extension in the official build, so this handler delegates to the
 * system `zstd` binary via proc_open without shell interpolation. Use the Tar
 * handler with decompression for .tar.zst archives.
 *
 * Check availability before use with `isAvailable()`.
 */
final class Zst
{
    /** Absolute or PATH-relative path to the zstd binary. */
    private string $binary;

    /**
     * @param string $binary Path to the zstd binary; defaults to `zstd` (resolved from PATH).
     */
    public function __construct(string $binary = 'zstd')
    {
        $this->binary = $binary !== '' ? $binary : 'zstd';
    }

    /**
     * Returns true when the zstd binary is reachable and executable.
     *
     * @return bool True when zstd is available on this system.
     */
    public function isAvailable(): bool
    {
        $result = $this->runBinary(['--version']);
        return $result['ok'];
    }

    /**
     * Compresses a file to a .zst file using Zstandard.
     *
     * Streams the source file through `zstd --compress` to the target path.
     * Creates intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the uncompressed source file.
     * @param string $targetPath Absolute path where the .zst output should land.
     * @param int    $level      Compression level 1 (fast) to 19 (best); default 3.
     * @return void
     * @throws RuntimeException When zstd is unavailable, the source cannot be read,
     *                          or the output cannot be written.
     */
    public function compress(string $sourcePath, string $targetPath, int $level = 3): void
    {
        // Compression is file-based, so fail before launching zstd when source is missing.
        if (!is_file($sourcePath)) {
            throw new RuntimeException('ZST source file not found: ' . $sourcePath);
        }

        $level = max(1, min(19, $level));

        $dir = dirname($targetPath);
        // Ensure destination parents exist before stdout redirection writes output files.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for ZST output: ' . $dir);
        }

        $result = $this->runBinary(
            ['--compress', '-' . $level, '--stdout', '--', $sourcePath],
            null,
            $targetPath
        );

        // Remove partial artifacts when zstd exits with a compression error.
        if (!$result['ok']) {
            @unlink($targetPath);
            throw new RuntimeException(
                'Zstandard compression failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
            );
        }
    }

    /**
     * Decompresses a .zst file to a plain file.
     *
     * Streams the compressed file through `zstd --decompress` to the target path.
     * Creates intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the .zst compressed file.
     * @param string $targetPath Absolute path where the decompressed output should land.
     * @return void
     * @throws RuntimeException When zstd is unavailable, the source cannot be read,
     *                          or the output cannot be written.
     */
    public function decompress(string $sourcePath, string $targetPath): void
    {
        // Decompression also requires a resolvable source file before process startup.
        if (!is_file($sourcePath)) {
            throw new RuntimeException('ZST source file not found: ' . $sourcePath);
        }

        $dir = dirname($targetPath);
        // Prepare decompression output parents so the redirected stream can be written.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for ZST decompression output: ' . $dir);
        }

        $result = $this->runBinary(
            ['--decompress', '--stdout', '--', $sourcePath],
            null,
            $targetPath
        );

        // Clean up incomplete targets when decompression fails.
        if (!$result['ok']) {
            @unlink($targetPath);
            throw new RuntimeException(
                'Zstandard decompression failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
            );
        }
    }

    /**
     * Runs the zstd binary with the given arguments via proc_open (no shell interpolation).
     *
     * Optionally writes stdout to a file path instead of capturing in memory.
     *
     * @param array<int, string> $args       Arguments to pass to the zstd binary.
     * @param string|null        $cwd        Working directory; null uses the PHP process cwd.
     * @param string|null        $outputFile When set, stdout is written to this file path.
     * @return array{ok: bool, exit_code: int, stderr: string} Result summary.
     */
    private function runBinary(array $args, ?string $cwd = null, ?string $outputFile = null): array
    {
        $command = array_merge([$this->binary], array_values($args));

        $stdoutSpec = $outputFile !== null
            ? ['file', $outputFile, 'w']
            : ['pipe', 'w'];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $stdoutSpec,
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);

        // Return normalized process-start failures so callers can emit clear errors.
        if (!is_resource($process)) {
            return ['ok' => false, 'exit_code' => -1, 'stderr' => 'Failed to start zstd process.'];
        }

        $stderr = '';

        // Close all allocated pipes before waiting for zstd to exit.
        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            // Consume stdout only when using an in-memory pipe instead of file redirection.
            if ($outputFile === null && isset($pipes[1]) && is_resource($pipes[1])) {
                stream_get_contents($pipes[1]);
                fclose($pipes[1]);
            }

            // Always capture stderr for actionable diagnostics on binary failures.
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);
            }
        } finally {
            $exitCode = proc_close($process);
        }

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stderr' => trim($stderr),
        ];
    }
}
