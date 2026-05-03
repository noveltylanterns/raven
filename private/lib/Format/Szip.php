<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Szip.php
 * 7-Zip archive handler via the system `7z` binary.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * Canonical 7-Zip archive handler for Raven core and extensions.
 *
 * This class wraps the system `7z` binary without shell interpolation so Raven
 * can create, inspect, and extract `.7z` archives through one reusable library
 * surface. The same binary also powers selective file/folder extraction, which
 * lets the higher-level archive forwarders expose one consistent API.
 */
final class Szip
{
    /** Absolute or PATH-relative path to the 7-Zip binary. */
    private string $binary;

    /**
     * @param string $binary Path to the 7-Zip binary; defaults to `7z`.
     */
    public function __construct(string $binary = '7z')
    {
        $this->binary = $binary !== '' ? $binary : '7z';
    }

    /**
     * Returns true when the `7z` binary is reachable and executable.
     *
     * @return bool True when 7-Zip can be used on this system.
     */
    public function isAvailable(): bool
    {
        $result = $this->runBinary(['i']);
        return $result['ok'];
    }

    /**
     * Extracts one full `.7z` archive into a target directory.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $targetDir Absolute path to the extraction directory.
     * @return void
     * @throws RuntimeException When the archive is missing or extraction fails.
     */
    public function extractTo(string $archivePath, string $targetDir): void
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('7Z archive not found: ' . $archivePath);
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create 7Z extraction target directory: ' . $targetDir);
        }

        $result = $this->runBinary([
            'x',
            '-y',
            '-bb0',
            '-bd',
            '-o' . $targetDir,
            '--',
            $archivePath,
        ]);

        if (!$result['ok']) {
            throw new RuntimeException(
                '7Z extraction failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
            );
        }
    }

    /**
     * Extracts one named archive entry to a target file path.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $entryName Archive-internal file path to extract.
     * @param string $targetPath Absolute output path for the extracted file.
     * @return void
     * @throws RuntimeException When the entry is unsafe, missing, or extraction fails.
     */
    public function extractFile(string $archivePath, string $entryName, string $targetPath): void
    {
        $entry = $this->normalizeEntryName($entryName);
        $temporaryDirectory = $this->tempDir();

        try {
            $this->extractEntries($archivePath, [$entry], $temporaryDirectory);

            $extractedPath = $temporaryDirectory . '/' . $entry;
            if (!is_file($extractedPath)) {
                throw new RuntimeException('Entry "' . $entry . '" not found in 7Z archive.');
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new RuntimeException('Failed to create directory for extracted 7Z entry.');
            }

            if (!@rename($extractedPath, $targetPath)) {
                $contents = @file_get_contents($extractedPath);
                if (!is_string($contents) || @file_put_contents($targetPath, $contents) === false) {
                    throw new RuntimeException('Failed to write extracted 7Z entry to target path.');
                }
            }
        } finally {
            $this->deleteTree($temporaryDirectory);
        }
    }

    /**
     * Extracts one named archive directory into a target directory.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $entryName Archive-internal directory path to extract.
     * @param string $targetDir Absolute directory that should receive the directory contents.
     * @return void
     * @throws RuntimeException When the directory is unsafe, missing, or extraction fails.
     */
    public function extractDir(string $archivePath, string $entryName, string $targetDir): void
    {
        $directory = $this->normalizeDirEntry($entryName);
        $matches = $this->dirEntries($archivePath, $directory);
        if ($matches === []) {
            throw new RuntimeException('Directory "' . trim($directory, '/') . '" not found in 7Z archive.');
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create 7Z extraction directory: ' . $targetDir);
        }

        foreach ($matches as $match) {
            $relative = ltrim(substr($match, strlen($directory)), '/');
            if ($relative === '') {
                continue;
            }

            $targetPath = $targetDir . '/' . $relative;

            // Directory entries do not carry data, but keeping them explicit
            // preserves empty folders when the archive records them directly.
            if (str_ends_with($match, '/')) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new RuntimeException('Failed to create extracted 7Z directory path.');
                }
                continue;
            }

            $this->extractFile($archivePath, $match, $targetPath);
        }
    }

    /**
     * Returns all entry names stored in a `.7z` archive.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @return array<int, string> Normalized archive-internal entry names.
     * @throws RuntimeException When the archive is missing or cannot be listed.
     */
    public function listEntries(string $archivePath): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('7Z archive not found: ' . $archivePath);
        }

        $result = $this->runBinary(['l', '-slt', '-bb0', '-bd', '--', $archivePath]);
        if (!$result['ok']) {
            throw new RuntimeException(
                '7Z listing failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
            );
        }

        $entries = [];
        $inEntries = false;

        foreach (preg_split("/\\r?\\n/", $result['stdout']) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '----------') {
                $inEntries = true;
                continue;
            }

            if (!$inEntries || !str_starts_with($trimmed, 'Path = ')) {
                continue;
            }

            $entry = trim(substr($trimmed, 7));
            if ($entry === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $entry);
            if ($this->isSafeEntryPath($normalized)) {
                $entries[] = $normalized;
            }
        }

        return $entries;
    }

    /**
     * Creates one `.7z` archive from a source file or directory.
     *
     * @param string $sourcePath Absolute path to the file or directory to archive.
     * @param string $outputPath Absolute output `.7z` path.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source is invalid or archive creation fails.
     */
    public function compressPath(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('7Z source path not found: ' . $sourcePath);
        }

        @unlink($outputPath);

        $stagingDirectory = $this->stagePath($sourcePath, $entryName);

        try {
            $relativeEntry = $this->stagedTarget($sourcePath, $entryName);
            $result = $this->runBinary([
                'a',
                '-t7z',
                '-y',
                '-bb0',
                '-bd',
                '--',
                $outputPath,
                $relativeEntry,
            ], $stagingDirectory);

            if (!$result['ok']) {
                @unlink($outputPath);
                throw new RuntimeException(
                    '7Z compression failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
                );
            }
        } finally {
            $this->deleteTree($stagingDirectory);
        }
    }

    /**
     * Adds or replaces one file or directory inside an existing `.7z` archive.
     *
     * @param string $archivePath Absolute path to the target archive.
     * @param string $sourcePath Absolute path to the file or directory to add.
     * @param string|null $entryName Optional archive-internal destination path.
     * @return void
     * @throws RuntimeException When the source is invalid or the update fails.
     */
    public function addPath(string $archivePath, string $sourcePath, ?string $entryName = null): void
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('7Z source path not found: ' . $sourcePath);
        }

        $stagingDirectory = $this->stagePath($sourcePath, $entryName);

        try {
            $relativeEntry = $this->stagedTarget($sourcePath, $entryName);
            $result = $this->runBinary([
                'a',
                '-y',
                '-bb0',
                '-bd',
                '--',
                $archivePath,
                $relativeEntry,
            ], $stagingDirectory);

            if (!$result['ok']) {
                throw new RuntimeException(
                    '7Z archive update failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
                );
            }
        } finally {
            $this->deleteTree($stagingDirectory);
        }
    }

    /**
     * Returns true when one archive-internal path is safe to extract.
     *
     * @param string $entryName Archive-internal path candidate.
     * @return bool True when the path is extraction-safe.
     */
    public function isSafeEntryPath(string $entryName): bool
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
     * Extracts a selected entry set into a temporary directory.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param array<int, string> $entries Archive-internal paths to extract.
     * @param string $targetDir Temporary extraction directory.
     * @return void
     * @throws RuntimeException When extraction fails.
     */
    private function extractEntries(string $archivePath, array $entries, string $targetDir): void
    {
        $command = [
            'x',
            '-y',
            '-bb0',
            '-bd',
            '-o' . $targetDir,
            '--',
            $archivePath,
        ];

        foreach ($entries as $entry) {
            $command[] = $entry;
        }

        $result = $this->runBinary($command);
        if (!$result['ok']) {
            throw new RuntimeException(
                '7Z extraction failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
            );
        }
    }

    /**
     * Returns all entry paths that live under one archive directory prefix.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $directory Archive-internal directory prefix ending in `/`.
     * @return array<int, string> Matching entry paths.
     */
    private function dirEntries(string $archivePath, string $directory): array
    {
        $matches = [];

        foreach ($this->listEntries($archivePath) as $entry) {
            $normalized = rtrim(str_replace('\\', '/', $entry), '/');
            if ($normalized === trim($directory, '/')) {
                $matches[] = $normalized . '/';
                continue;
            }

            if (str_starts_with($entry, $directory)) {
                $matches[] = $entry;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * Creates one temporary staging directory and copies a path into it.
     *
     * A dedicated staging area lets Raven control the final archive entry name
     * without renaming operator files in place or passing risky absolute paths
     * directly to archive binaries.
     *
     * @param string $sourcePath Absolute file or directory path to stage.
     * @param string|null $entryName Optional archive-internal root path.
     * @return string Absolute temporary staging directory path.
     * @throws RuntimeException When the source cannot be staged.
     */
    private function stagePath(string $sourcePath, ?string $entryName): string
    {
        $stagingDirectory = $this->tempDir();
        $stagedEntryPath = $this->stagedPath($sourcePath, $entryName);
        $destinationPath = $stagingDirectory . '/' . $stagedEntryPath;

        $destinationDirectory = dirname($destinationPath);
        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            $this->deleteTree($stagingDirectory);
            throw new RuntimeException('Failed to prepare 7Z staging directory.');
        }

        try {
            if (is_dir($sourcePath)) {
                $this->copyTree($sourcePath, $destinationPath);
            } else {
                if (!@copy($sourcePath, $destinationPath)) {
                    throw new RuntimeException('Failed to copy file into 7Z staging directory.');
                }
            }
        } catch (\Throwable $exception) {
            $this->deleteTree($stagingDirectory);
            throw $exception;
        }

        return $stagingDirectory;
    }

    /**
     * Returns the staged relative path for one source path.
     *
     * @param string $sourcePath Absolute file or directory path to stage.
     * @param string|null $entryName Optional archive-internal root path.
     * @return string Relative path inside the temporary staging directory.
     */
    private function stagedPath(string $sourcePath, ?string $entryName): string
    {
        if (is_string($entryName) && trim($entryName) !== '') {
            return $this->normalizeEntryName($entryName);
        }

        return trim(basename($sourcePath), '/');
    }

    /**
     * Returns the top-level staging target that 7-Zip should archive.
     *
     * Nested entry names are staged under their requested parent directories, so
     * the command only needs the first path segment to include the full tree.
     *
     * @param string $sourcePath Absolute file or directory path to stage.
     * @param string|null $entryName Optional archive-internal root path.
     * @return string Relative staging target for the `7z a` command.
     */
    private function stagedTarget(string $sourcePath, ?string $entryName): string
    {
        $stagedEntryPath = $this->stagedPath($sourcePath, $entryName);
        $segments = explode('/', $stagedEntryPath);

        return $segments[0] !== '' ? $segments[0] : trim(basename($sourcePath), '/');
    }

    /**
     * Normalizes one archive-internal path and rejects traversal.
     *
     * @param string $entryName Archive-internal path candidate.
     * @return string Safe normalized path.
     * @throws RuntimeException When the path is unsafe.
     */
    private function normalizeEntryName(string $entryName): string
    {
        $normalized = trim(str_replace('\\', '/', $entryName), '/');
        if (!$this->isSafeEntryPath($normalized)) {
            throw new RuntimeException('Unsafe 7Z entry path: ' . $entryName);
        }

        return $normalized;
    }

    /**
     * Normalizes one archive directory prefix and guarantees a trailing slash.
     *
     * @param string $entryName Archive-internal directory path candidate.
     * @return string Safe normalized directory prefix ending in `/`.
     * @throws RuntimeException When the path is unsafe.
     */
    private function normalizeDirEntry(string $entryName): string
    {
        return rtrim($this->normalizeEntryName($entryName), '/') . '/';
    }

    /**
     * Allocates one unique temporary directory for staging or extraction work.
     *
     * @return string Absolute temporary directory path.
     * @throws RuntimeException When the directory cannot be created.
     */
    private function tempDir(): string
    {
        foreach ($this->tempRoots() as $directory) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $path = $directory . '/rvn-7z-' . bin2hex(random_bytes(6));
                if (mkdir($path, 0775, true)) {
                    return $path;
                }

                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        throw new RuntimeException('Failed to allocate temporary 7Z directory.');
    }

    /**
     * Returns writable directory roots Raven can use for temporary 7-Zip work.
     *
     * Exports often run under locked-down PHP temp settings, so falling back to
     * project-local `.tmp` directories keeps archive creation working when the
     * system temp directory is unavailable to the current runtime user.
     *
     * @return array<int, string> Writable temporary directory roots.
     */
    private function tempRoots(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $candidates = [
            trim((string) sys_get_temp_dir()),
            $projectRoot . '/.tmp',
            $projectRoot . '/.tmp/archives',
        ];
        $directories = [];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
                continue;
            }

            if (!is_writable($candidate)) {
                continue;
            }

            $directories[] = rtrim($candidate, '/\\');
        }

        return array_values(array_unique($directories));
    }

    /**
     * Copies one directory tree recursively while skipping symlinks.
     *
     * @param string $sourceDir Absolute source directory.
     * @param string $targetDir Absolute destination directory.
     * @return void
     * @throws RuntimeException When one file or directory cannot be copied.
     */
    private function copyTree(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create 7Z staging subdirectory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $absolutePath = $item->getPathname();
            $relativePath = ltrim(substr($absolutePath, strlen($sourceDir)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $destinationPath = $targetDir . '/' . str_replace('\\', '/', $relativePath);
            if ($item->isDir()) {
                if (!is_dir($destinationPath) && !mkdir($destinationPath, 0775, true) && !is_dir($destinationPath)) {
                    throw new RuntimeException('Failed to create 7Z staging directory path.');
                }
                continue;
            }

            $destinationDirectory = dirname($destinationPath);
            if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
                throw new RuntimeException('Failed to create 7Z staging parent directory.');
            }

            if (!@copy($absolutePath, $destinationPath)) {
                throw new RuntimeException('Failed to copy file into 7Z staging directory.');
            }
        }
    }

    /**
     * Deletes one temporary directory tree recursively.
     *
     * @param string $directory Absolute directory path to delete.
     * @return void
     */
    private function deleteTree(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * Runs one 7-Zip command via `proc_open` without shell interpolation.
     *
     * @param array<int, string> $arguments 7-Zip arguments excluding the binary.
     * @param string|null $workingDirectory Optional working directory.
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string} Result summary.
     */
    private function runBinary(array $arguments, ?string $workingDirectory = null): array
    {
        $command = array_merge([$this->binary], array_values($arguments));

        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory
        );

        if (!is_resource($process)) {
            return [
                'ok' => false,
                'exit_code' => -1,
                'stdout' => '',
                'stderr' => 'Failed to start 7Z process.',
            ];
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
}
