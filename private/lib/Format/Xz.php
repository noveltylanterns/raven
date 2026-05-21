<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Xz.php
 * XZ single-file compression and decompression handler via the xz binary.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * XZ single-file handler for Raven core and extensions.
 *
 * XZ is a file-level compression format. PHP has no native xz extension, so
 * this handler delegates to the system `xz` binary via proc_open without shell
 * interpolation. Use the Tar handler with decompression for .tar.xz archives.
 *
 * Check availability before use with `isAvailable()`.
 */
final class Xz
{
    /** Absolute or PATH-relative path to the xz binary. */
    private string $binary;

    /**
     * @param string $binary Path to the xz binary; defaults to `xz` (resolved from PATH).
     */
    public function __construct(string $binary = 'xz')
    {
        $this->binary = $binary !== '' ? $binary : 'xz';
    }

    /**
     * Returns true when the xz binary is reachable and executable.
     *
     * @return bool True when xz is available on this system.
     */
    public function isAvailable(): bool
    {
        $result = $this->runBinary(['--version']);
        return $result['ok'];
    }

    /**
     * Compresses a file to a .xz file.
     *
     * Streams the source file through `xz --compress` to the target path.
     * Creates intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the uncompressed source file.
     * @param string $targetPath Absolute path where the .xz output should land.
     * @param int    $level      Compression level 0 (fast) to 9 (best); default 6.
     * @return void
     * @throws RuntimeException When xz is unavailable, the source cannot be read,
     *                          or the output cannot be written.
     */
    public function compress(string $sourcePath, string $targetPath, int $level = 6): void
    {
        // Compression is file-based, so fail before spawning xz when the source is missing.
        if (!is_file($sourcePath)) {
            throw new RuntimeException('XZ source file not found: ' . $sourcePath);
        }

        $level = max(0, min(9, $level));

        $dir = dirname($targetPath);
        // Pre-create the destination directory so xz output redirection can open its file.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for XZ output: ' . $dir);
        }

        $result = $this->runBinary(
            ['--compress', '-' . $level, '--stdout', '--', $sourcePath],
            null,
            $targetPath
        );

        // Remove partial targets on process failures to avoid publishing truncated artifacts.
        if (!$result['ok']) {
            @unlink($targetPath);
            throw new RuntimeException(
                'XZ compression failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
            );
        }
    }

    /**
     * Decompresses a .xz file to a plain file.
     *
     * Streams the compressed file through `xz --decompress` to the target path.
     * Creates intermediate directories for the output path as needed.
     *
     * @param string $sourcePath Absolute path to the .xz compressed file.
     * @param string $targetPath Absolute path where the decompressed output should land.
     * @return void
     * @throws RuntimeException When xz is unavailable, the source cannot be read,
     *                          or the output cannot be written.
     */
    public function decompress(string $sourcePath, string $targetPath): void
    {
        // Decompression also requires one real source file before launching xz.
        if (!is_file($sourcePath)) {
            throw new RuntimeException('XZ source file not found: ' . $sourcePath);
        }

        $dir = dirname($targetPath);
        // Ensure destination parents exist so stdout redirection can create the output file.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for XZ decompression output: ' . $dir);
        }

        $result = $this->runBinary(
            ['--decompress', '--stdout', '--', $sourcePath],
            null,
            $targetPath
        );

        // Clean up incomplete decompressed files when xz exits with an error.
        if (!$result['ok']) {
            @unlink($targetPath);
            throw new RuntimeException(
                'XZ decompression failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
            );
        }
    }

    /**
     * Runs the xz binary with the given arguments via proc_open (no shell interpolation).
     *
     * Optionally writes stdout to a file path instead of capturing it in memory,
     * which prevents loading large archives into RAM.
     *
     * @param array<int, string> $args       Arguments to pass to the xz binary.
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

        // Return a normalized failure shape when process startup fails.
        if (!is_resource($process)) {
            return ['ok' => false, 'exit_code' => -1, 'stderr' => 'Failed to start xz process.'];
        }

        $stderr = '';

        // Close every opened pipe predictably before waiting on process exit.
        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            // Capture stdout only when it was piped instead of file-redirected.
            if ($outputFile === null && isset($pipes[1]) && is_resource($pipes[1])) {
                stream_get_contents($pipes[1]);
                fclose($pipes[1]);
            }

            // stderr is always collected so callers can report binary failures clearly.
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
