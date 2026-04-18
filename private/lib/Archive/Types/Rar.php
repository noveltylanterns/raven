<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Types/Rar.php
 * RAR archive extraction handler via the rar PHP extension or unrar binary.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive\Types;

use RuntimeException;

/**
 * RAR archive handler for Raven core and extensions.
 *
 * RAR is a proprietary format; creation requires a licensed `rar` binary.
 * This handler supports extraction only, using the PHP rar extension when
 * available and falling back to the system `unrar` binary via proc_open
 * without shell interpolation.
 *
 * All entry paths are validated for zip-slip-style traversal before extraction.
 * Check availability before use with `isAvailable()`.
 */
final class Rar
{
    /** Absolute or PATH-relative path to the unrar binary. */
    private string $binary;

    /**
     * @param string $binary Path to the unrar binary; defaults to `unrar` (resolved from PATH).
     */
    public function __construct(string $binary = 'unrar')
    {
        $this->binary = $binary !== '' ? $binary : 'unrar';
    }

    /**
     * Returns true when RAR extraction is available on this system.
     *
     * Checks first for the PHP rar extension, then for the unrar binary.
     *
     * @return bool True when at least one extraction method is available.
     */
    public function isAvailable(): bool
    {
        if (extension_loaded('rar')) {
            return true;
        }

        $result = $this->runBinary(['--version']);
        return $result['ok'];
    }

    /**
     * Extracts all entries from a RAR archive into a target directory.
     *
     * Uses the PHP rar extension when loaded; falls back to the unrar binary.
     * All entry paths are validated against traversal patterns before extraction.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @param string $targetDir   Absolute path to the destination directory.
     * @return void
     * @throws RuntimeException When no extraction method is available, the archive
     *                          cannot be opened, or extraction fails.
     */
    public function extractTo(string $archivePath, string $targetDir): void
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('RAR archive not found: ' . $archivePath);
        }

        if (extension_loaded('rar')) {
            $this->extractToViaExtension($archivePath, $targetDir);
            return;
        }

        $this->extractToViaBinary($archivePath, $targetDir);
    }

    /**
     * Extracts a single named entry from a RAR archive to a target file path.
     *
     * Uses the PHP rar extension when loaded; falls back to the unrar binary.
     * Validates the entry path against traversal patterns before extraction.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @param string $entryName   RAR-internal entry name to extract.
     * @param string $targetPath  Absolute path where the extracted file should land.
     * @return void
     * @throws RuntimeException When the entry is unsafe, not found, or extraction fails.
     */
    public function extractFile(string $archivePath, string $entryName, string $targetPath): void
    {
        if (!$this->isSafeEntryPath($entryName)) {
            throw new RuntimeException('Unsafe RAR entry path: ' . $entryName);
        }

        if (!is_file($archivePath)) {
            throw new RuntimeException('RAR archive not found: ' . $archivePath);
        }

        if (extension_loaded('rar')) {
            $this->extractFileViaExtension($archivePath, $entryName, $targetPath);
            return;
        }

        $this->extractFileViaBinary($archivePath, $entryName, $targetPath);
    }

    /**
     * Returns all entry names in a RAR archive as an indexed array.
     *
     * Uses the PHP rar extension when loaded; falls back to the unrar binary.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @return array<int, string> List of entry names.
     * @throws RuntimeException When no extraction method is available or the archive fails to open.
     */
    public function listEntries(string $archivePath): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('RAR archive not found: ' . $archivePath);
        }

        if (extension_loaded('rar')) {
            return $this->listEntriesViaExtension($archivePath);
        }

        return $this->listEntriesViaBinary($archivePath);
    }

    /**
     * Extracts all entries using the PHP rar extension.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @param string $targetDir   Absolute path to the destination directory.
     * @return void
     * @throws RuntimeException On open failure, unsafe paths, or extraction errors.
     */
    private function extractToViaExtension(string $archivePath, string $targetDir): void
    {
        /** @var \RarArchive|false $rar */
        $rar = \RarArchive::open($archivePath);
        if ($rar === false) {
            throw new RuntimeException('Failed to open RAR archive: ' . $archivePath);
        }

        try {
            $entries = $rar->getEntries();
            if ($entries === false) {
                throw new RuntimeException('Failed to read entries from RAR archive.');
            }

            foreach ($entries as $entry) {
                $name = $entry->getName();
                if (!$this->isSafeEntryPath($name)) {
                    throw new RuntimeException('RAR archive contains an unsafe entry path: ' . $name);
                }

                if (!$entry->extract($targetDir)) {
                    throw new RuntimeException('Failed to extract RAR entry: ' . $name);
                }
            }
        } finally {
            $rar->close();
        }
    }

    /**
     * Extracts all entries using the unrar binary.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @param string $targetDir   Absolute path to the destination directory.
     * @return void
     * @throws RuntimeException When unrar is unavailable or extraction fails.
     */
    private function extractToViaBinary(string $archivePath, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create RAR extraction target directory: ' . $targetDir);
        }

        // `unrar x` extracts with full paths; `-y` answers yes to all prompts.
        $result = $this->runBinary(['x', '-y', '--', $archivePath, $targetDir . '/']);

        if (!$result['ok']) {
            throw new RuntimeException(
                'unrar extraction failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
            );
        }
    }

    /**
     * Extracts a single entry using the PHP rar extension.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @param string $entryName   RAR-internal entry name.
     * @param string $targetPath  Absolute output path.
     * @return void
     * @throws RuntimeException On open failure or extraction errors.
     */
    private function extractFileViaExtension(string $archivePath, string $entryName, string $targetPath): void
    {
        /** @var \RarArchive|false $rar */
        $rar = \RarArchive::open($archivePath);
        if ($rar === false) {
            throw new RuntimeException('Failed to open RAR archive: ' . $archivePath);
        }

        try {
            $entry = $rar->getEntry($entryName);
            if ($entry === false) {
                throw new RuntimeException('Entry "' . $entryName . '" not found in RAR archive.');
            }

            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory for RAR extraction.');
            }

            // Extract to the parent directory; unrar recreates the entry filename.
            if (!$entry->extract($dir, $targetPath)) {
                throw new RuntimeException('Failed to extract RAR entry "' . $entryName . '".');
            }
        } finally {
            $rar->close();
        }
    }

    /**
     * Extracts a single entry using the unrar binary to a temporary directory,
     * then moves the result to the target path.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @param string $entryName   RAR-internal entry name.
     * @param string $targetPath  Absolute output path.
     * @return void
     * @throws RuntimeException When unrar is unavailable or extraction fails.
     */
    private function extractFileViaBinary(string $archivePath, string $entryName, string $targetPath): void
    {
        $tmpDir = sys_get_temp_dir() . '/rvn-rar-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Failed to create temporary directory for RAR entry extraction.');
        }

        try {
            $result = $this->runBinary(['x', '-y', '--', $archivePath, $entryName, $tmpDir . '/']);
            if (!$result['ok']) {
                throw new RuntimeException(
                    'unrar single-file extraction failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
                );
            }

            $extracted = $tmpDir . '/' . $entryName;
            if (!is_file($extracted)) {
                throw new RuntimeException('unrar did not produce expected output file for entry "' . $entryName . '".');
            }

            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory for RAR entry target path.');
            }

            if (!rename($extracted, $targetPath)) {
                throw new RuntimeException('Failed to move extracted RAR entry to target path.');
            }
        } finally {
            $this->deleteDirectoryRecursively($tmpDir);
        }
    }

    /**
     * Lists entries using the PHP rar extension.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @return array<int, string> Entry names.
     * @throws RuntimeException On open failure.
     */
    private function listEntriesViaExtension(string $archivePath): array
    {
        /** @var \RarArchive|false $rar */
        $rar = \RarArchive::open($archivePath);
        if ($rar === false) {
            throw new RuntimeException('Failed to open RAR archive: ' . $archivePath);
        }

        try {
            $entries = $rar->getEntries();
            if ($entries === false) {
                return [];
            }

            $names = [];
            foreach ($entries as $entry) {
                $names[] = $entry->getName();
            }

            return $names;
        } finally {
            $rar->close();
        }
    }

    /**
     * Lists entries by parsing the unrar `lb` listing output.
     *
     * @param string $archivePath Absolute path to the .rar file.
     * @return array<int, string> Entry names.
     * @throws RuntimeException When unrar is unavailable or listing fails.
     */
    private function listEntriesViaBinary(string $archivePath): array
    {
        $result = $this->runBinary(['lb', '--', $archivePath]);
        if (!$result['ok'] && $result['exit_code'] !== 0) {
            throw new RuntimeException(
                'unrar listing failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'unknown error')
            );
        }

        $entries = [];
        foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * Returns true when a RAR entry path is safe to extract.
     *
     * @param string $entryName Raw entry name from the RAR directory.
     * @return bool True when the path is safe.
     */
    private function isSafeEntryPath(string $entryName): bool
    {
        $path = str_replace('\\', '/', trim($entryName));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs the unrar binary with the given arguments via proc_open (no shell interpolation).
     *
     * @param array<int, string> $args Arguments to pass to the unrar binary.
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string} Result summary.
     */
    private function runBinary(array $args): array
    {
        $command = array_merge([$this->binary], array_values($args));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['ok' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Failed to start unrar process.'];
        }

        $stdout = '';
        $stderr = '';

        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
            }

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
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    /**
     * Recursively deletes a directory and all its contents.
     *
     * @param string $directory Absolute path to the directory to remove.
     * @return void
     */
    private function deleteDirectoryRecursively(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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
}
