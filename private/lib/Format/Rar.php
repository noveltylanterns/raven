<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Rar.php
 * RAR archive handler via the PHP rar extension, unrar, 7z, and rar binaries.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;

/**
 * RAR archive handler for Raven core and extensions.
 *
 * RAR is a proprietary format, so creation requires an external `rar` binary.
 * Extraction prefers the PHP rar extension when available, falls back to the
 * system `unrar` binary, and finally uses the shared `7z` binary when neither
 * native RAR option is present.
 *
 * All entry paths are validated for zip-slip-style traversal before extraction.
 * Check availability before use with `isAvailable()`.
 */
final class Rar
{
    /** Absolute or PATH-relative path to the unrar binary. */
    private string $binary;
    /** Absolute or PATH-relative path to the rar binary. */
    private string $compressionBinary;
    /** Shared 7-Zip helper used as the last extraction fallback. */
    private SevenZip $sevenZip;

    /**
     * @param string $binary Path to the unrar binary; defaults to `unrar`.
     * @param string $compressionBinary Path to the rar binary; defaults to `rar`.
     * @param SevenZip|null $sevenZip Shared 7-Zip helper override for tests/composition.
     */
    public function __construct(string $binary = 'unrar', string $compressionBinary = 'rar', ?SevenZip $sevenZip = null)
    {
        $this->binary = $binary !== '' ? $binary : 'unrar';
        $this->compressionBinary = $compressionBinary !== '' ? $compressionBinary : 'rar';
        $this->sevenZip = $sevenZip ?? new SevenZip();
    }

    /**
     * Returns true when RAR extraction is available on this system.
     *
     * Checks first for the PHP rar extension, then for `unrar`, then for `7z`.
     *
     * @return bool True when at least one extraction method is available.
     */
    public function isAvailable(): bool
    {
        if (extension_loaded('rar')) {
            return true;
        }

        return $this->canUseExtractBinary() || $this->sevenZip->isAvailable();
    }

    /**
     * Returns true when the proprietary `rar` binary is reachable and executable.
     *
     * @return bool True when Raven can create or update `.rar` archives.
     */
    public function isCompressionAvailable(): bool
    {
        return $this->canUseCompressionBinary();
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

        if ($this->canUseExtractBinary()) {
            $this->extractToViaBinary($archivePath, $targetDir);
            return;
        }

        if ($this->sevenZip->isAvailable()) {
            $this->sevenZip->extractTo($archivePath, $targetDir);
            return;
        }

        throw new RuntimeException('RAR extraction is not available on this system.');
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

        if ($this->canUseExtractBinary()) {
            $this->extractFileViaBinary($archivePath, $entryName, $targetPath);
            return;
        }

        if ($this->sevenZip->isAvailable()) {
            $this->sevenZip->extractFile($archivePath, $entryName, $targetPath);
            return;
        }

        throw new RuntimeException('RAR extraction is not available on this system.');
    }

    /**
     * Extracts one named RAR directory into a target directory.
     *
     * @param string $archivePath Absolute path to the `.rar` file.
     * @param string $entryName RAR-internal directory path to extract.
     * @param string $targetDir Absolute path that should receive the directory contents.
     * @return void
     * @throws RuntimeException When the directory is unsafe, missing, or extraction fails.
     */
    public function extractDirectory(string $archivePath, string $entryName, string $targetDir): void
    {
        $directory = $this->normalizeDirectoryEntryName($entryName);
        $matches = [];

        foreach ($this->listEntries($archivePath) as $entry) {
            $normalized = rtrim(str_replace('\\', '/', $entry), '/');
            if ($normalized === trim($directory, '/')) {
                $matches[] = $normalized . '/';
                continue;
            }

            if (str_starts_with(str_replace('\\', '/', $entry), $directory)) {
                $matches[] = str_replace('\\', '/', $entry);
            }
        }

        if ($matches === []) {
            throw new RuntimeException('Directory "' . trim($directory, '/') . '" not found in RAR archive.');
        }

        foreach (array_values(array_unique($matches)) as $match) {
            $relative = ltrim(substr($match, strlen($directory)), '/');
            if ($relative === '') {
                continue;
            }

            $targetPath = $targetDir . '/' . $relative;
            if (str_ends_with($match, '/')) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new RuntimeException('Failed to create directory for extracted RAR entry.');
                }
                continue;
            }

            $this->extractFile($archivePath, $match, $targetPath);
        }
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

        if ($this->canUseExtractBinary()) {
            return $this->listEntriesViaBinary($archivePath);
        }

        if ($this->sevenZip->isAvailable()) {
            return $this->sevenZip->listEntries($archivePath);
        }

        throw new RuntimeException('RAR listing is not available on this system.');
    }

    /**
     * Creates one `.rar` archive from a source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $outputPath Absolute path where the `.rar` file should be written.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source is invalid or RAR creation is unavailable.
     */
    public function compressPath(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('RAR source path not found: ' . $sourcePath);
        }

        if (!$this->isCompressionAvailable()) {
            throw new RuntimeException('RAR compression requires the proprietary `rar` binary.');
        }

        $archiveDirectory = dirname($outputPath);
        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new RuntimeException('Failed to create RAR output directory.');
        }

        $stagingDirectory = $this->stagePath($sourcePath, $entryName);

        try {
            $commandTarget = $this->stagedCommandTarget($sourcePath, $entryName);
            $result = $this->runCompressionBinary(['a', '-r', '-idq', $outputPath, $commandTarget], $stagingDirectory);
            if (!$result['ok']) {
                @unlink($outputPath);
                throw new RuntimeException(
                    'RAR compression failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
                );
            }
        } finally {
            $this->deleteDirectoryRecursively($stagingDirectory);
        }
    }

    /**
     * Adds or replaces one file or directory inside an existing `.rar` archive.
     *
     * @param string $archivePath Absolute path to the target `.rar` archive.
     * @param string $sourcePath Absolute path to the file or directory to add.
     * @param string|null $entryName Optional archive-internal destination path.
     * @return void
     * @throws RuntimeException When the source is invalid or RAR updates are unavailable.
     */
    public function addPath(string $archivePath, string $sourcePath, ?string $entryName = null): void
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('RAR source path not found: ' . $sourcePath);
        }

        if (!$this->isCompressionAvailable()) {
            throw new RuntimeException('RAR compression requires the proprietary `rar` binary.');
        }

        $archiveDirectory = dirname($archivePath);
        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new RuntimeException('Failed to create RAR archive directory.');
        }

        $stagingDirectory = $this->stagePath($sourcePath, $entryName);

        try {
            $commandTarget = $this->stagedCommandTarget($sourcePath, $entryName);
            $result = $this->runCompressionBinary(['a', '-r', '-idq', $archivePath, $commandTarget], $stagingDirectory);
            if (!$result['ok']) {
                throw new RuntimeException(
                    'RAR archive update failed: ' . ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
                );
            }
        } finally {
            $this->deleteDirectoryRecursively($stagingDirectory);
        }
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
        $result = $this->runExtractBinary(['x', '-y', '--', $archivePath, $targetDir . '/']);

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
            $result = $this->runExtractBinary(['x', '-y', '--', $archivePath, $entryName, $tmpDir . '/']);
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
        $result = $this->runExtractBinary(['lb', '--', $archivePath]);
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
     * Normalizes one archive-internal entry path and rejects traversal.
     *
     * @param string $entryName Archive-internal path candidate.
     * @return string Safe normalized path.
     * @throws RuntimeException When the path is unsafe.
     */
    private function normalizeEntryName(string $entryName): string
    {
        $normalized = trim(str_replace('\\', '/', $entryName), '/');
        if (!$this->isSafeEntryPath($normalized)) {
            throw new RuntimeException('Unsafe RAR entry path: ' . $entryName);
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
    private function normalizeDirectoryEntryName(string $entryName): string
    {
        return rtrim($this->normalizeEntryName($entryName), '/') . '/';
    }

    /**
     * Returns true when the `unrar` binary can be started on this system.
     *
     * @return bool True when `unrar` is available.
     */
    private function canUseExtractBinary(): bool
    {
        return $this->runExtractBinary([])['exit_code'] !== -1;
    }

    /**
     * Returns true when the `rar` binary can be started on this system.
     *
     * @return bool True when `rar` is available.
     */
    private function canUseCompressionBinary(): bool
    {
        return $this->runCompressionBinary([])['exit_code'] !== -1;
    }

    /**
     * Runs the unrar binary with the given arguments via proc_open (no shell interpolation).
     *
     * @param array<int, string> $args Arguments to pass to the unrar binary.
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string} Result summary.
     */
    private function runExtractBinary(array $args): array
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
     * Runs the rar binary with the given arguments via proc_open.
     *
     * @param array<int, string> $args Arguments to pass to the rar binary.
     * @param string|null $cwd Optional working directory for staged archive input.
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string} Result summary.
     */
    private function runCompressionBinary(array $args, ?string $cwd = null): array
    {
        $command = array_merge([$this->compressionBinary], array_values($args));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return ['ok' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Failed to start rar process.'];
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
     * Creates one temporary staging directory and copies a path into it.
     *
     * @param string $sourcePath Absolute file or directory path to stage.
     * @param string|null $entryName Optional archive-internal root path.
     * @return string Absolute temporary staging directory.
     * @throws RuntimeException When the path cannot be staged.
     */
    private function stagePath(string $sourcePath, ?string $entryName): string
    {
        $stagingDirectory = sys_get_temp_dir() . '/rvn-rar-' . bin2hex(random_bytes(6));
        if (!mkdir($stagingDirectory, 0775, true) && !is_dir($stagingDirectory)) {
            throw new RuntimeException('Failed to allocate temporary RAR staging directory.');
        }

        $stagedEntryPath = $this->stagedEntryPath($sourcePath, $entryName);
        $destinationPath = $stagingDirectory . '/' . $stagedEntryPath;
        $destinationDirectory = dirname($destinationPath);

        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            $this->deleteDirectoryRecursively($stagingDirectory);
            throw new RuntimeException('Failed to prepare RAR staging directory.');
        }

        try {
            if (is_dir($sourcePath)) {
                $this->copyDirectoryRecursively($sourcePath, $destinationPath);
            } else {
                if (!@copy($sourcePath, $destinationPath)) {
                    throw new RuntimeException('Failed to copy file into RAR staging directory.');
                }
            }
        } catch (\Throwable $exception) {
            $this->deleteDirectoryRecursively($stagingDirectory);
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
    private function stagedEntryPath(string $sourcePath, ?string $entryName): string
    {
        if (is_string($entryName) && trim($entryName) !== '') {
            return $this->normalizeEntryName($entryName);
        }

        return trim(basename($sourcePath), '/');
    }

    /**
     * Returns the top-level staging target that the `rar a` command should add.
     *
     * @param string $sourcePath Absolute file or directory path to stage.
     * @param string|null $entryName Optional archive-internal root path.
     * @return string Relative staging target for the `rar a` command.
     */
    private function stagedCommandTarget(string $sourcePath, ?string $entryName): string
    {
        $stagedEntryPath = $this->stagedEntryPath($sourcePath, $entryName);
        $segments = explode('/', $stagedEntryPath);

        return $segments[0] !== '' ? $segments[0] : trim(basename($sourcePath), '/');
    }

    /**
     * Copies one directory tree recursively while skipping symlinks.
     *
     * @param string $sourceDir Absolute source directory path.
     * @param string $targetDir Absolute destination directory path.
     * @return void
     * @throws RuntimeException When one file or directory cannot be copied.
     */
    private function copyDirectoryRecursively(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create RAR staging subdirectory.');
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
                    throw new RuntimeException('Failed to create RAR staging directory path.');
                }
                continue;
            }

            $destinationDirectory = dirname($destinationPath);
            if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
                throw new RuntimeException('Failed to create RAR staging parent directory.');
            }

            if (!@copy($absolutePath, $destinationPath)) {
                throw new RuntimeException('Failed to copy file into RAR staging directory.');
            }
        }
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
