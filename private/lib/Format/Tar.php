<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Tar.php
 * TAR archive handler — extract, inspect, and build TAR-family archives.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use PharData;
use RuntimeException;

/**
 * Canonical TAR archive handler for Raven core and extensions.
 *
 * Handles safe extraction, whole-archive creation, selective file/folder
 * extraction, and path addition for `.tar` archives via PHP's PharData. For
 * `.tar.gz`, `.tar.bz2`, `.tar.xz`, and `.tar.zst`, the higher-level archive
 * forwarders still peel off or apply the outer single-file compression layer.
 *
 * Path traversal safety: PharData rejects `..` segments natively during
 * extraction. For single-file extraction, entry names are validated before use.
 */
final class Tar
{
    /**
     * Extracts all entries from a TAR (or .tar.gz) archive into a directory.
     *
     * Accepts .tar and .tar.gz (a.k.a. .tgz) archives. PharData raises a
     * BadMethodCallException or UnexpectedValueException on corrupt archives.
     *
     * @param string      $archivePath Absolute path to the .tar or .tar.gz file.
     * @param string      $targetDir   Absolute path to the destination directory.
     * @param string|null $subDir      Optional sub-directory inside the archive to
     *                                 extract; when null all entries are extracted.
     * @return void
     * @throws RuntimeException When the archive cannot be opened or extraction fails.
     */
    public function extractTo(string $archivePath, string $targetDir, ?string $subDir = null): void
    {
        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($targetDir, $subDir, true);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to extract TAR archive "' . basename($archivePath) . '": ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Extracts a single named entry from a TAR (or .tar.gz) archive.
     *
     * Validates the entry name against traversal patterns before delegating to
     * PharData. Creates intermediate directories for the target path as needed.
     *
     * @param string $archivePath Absolute path to the .tar or .tar.gz file.
     * @param string $entryName   TAR-internal entry name to extract.
     * @param string $targetPath  Absolute path where the extracted file should land.
     * @return void
     * @throws RuntimeException When the entry is unsafe, not found, or write fails.
     */
    public function extractFile(string $archivePath, string $entryName, string $targetPath): void
    {
        $entry = $this->normalizeEntryName($entryName);

        try {
            $phar = new PharData($archivePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to open TAR archive "' . basename($archivePath) . '": ' . $e->getMessage(),
                0,
                $e
            );
        }

        // PharData exposes entries via ArrayAccess keyed by entry name.
        if (!isset($phar[$entry])) {
            throw new RuntimeException('Entry "' . $entry . '" not found in TAR archive.');
        }

        $contents = file_get_contents('phar://' . $archivePath . '/' . $entry);
        if ($contents === false) {
            throw new RuntimeException('Failed to read entry "' . $entry . '" from TAR archive.');
        }

        $this->writeExtractedEntry($targetPath, $contents);
    }

    /**
     * Extracts one named TAR directory into a target directory.
     *
     * @param string $archivePath Absolute path to the `.tar` or `.tar.gz` file.
     * @param string $entryName TAR-internal directory path to extract.
     * @param string $targetDir Absolute path that should receive the directory contents.
     * @return void
     * @throws RuntimeException When the directory is unsafe, missing, or extraction fails.
     */
    public function extractDirectory(string $archivePath, string $entryName, string $targetDir): void
    {
        $directory = $this->normalizeDirectoryEntryName($entryName);
        $matches = [];

        foreach ($this->listEntries($archivePath) as $entry) {
            $normalized = rtrim($entry, '/');
            if ($normalized === trim($directory, '/')) {
                $matches[] = $normalized . '/';
                continue;
            }

            if (str_starts_with($entry, $directory)) {
                $matches[] = $entry;
            }
        }

        if ($matches === []) {
            throw new RuntimeException('Directory "' . trim($directory, '/') . '" not found in TAR archive.');
        }

        foreach (array_values(array_unique($matches)) as $match) {
            $relative = ltrim(substr($match, strlen($directory)), '/');
            if ($relative === '') {
                continue;
            }

            $targetPath = $targetDir . '/' . $relative;
            if (str_ends_with($match, '/')) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new RuntimeException('Failed to create directory for extracted TAR entry.');
                }
                continue;
            }

            $this->extractFile($archivePath, $match, $targetPath);
        }
    }

    /**
     * Creates a `.tar` archive from one source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $outputPath Absolute path where the `.tar` file should be written.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source path is invalid or archiving fails.
     */
    public function compressPath(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('TAR source path could not be resolved: ' . $sourcePath);
        }

        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Failed to create TAR output directory.');
        }

        @unlink($outputPath);

        try {
            $phar = new PharData($outputPath);

            if (is_dir($sourcePath)) {
                $sourceRoot = realpath($sourcePath);
                if ($sourceRoot === false || !is_dir($sourceRoot)) {
                    throw new RuntimeException('TAR source directory could not be resolved: ' . $sourcePath);
                }

                if ($entryName === null || trim($entryName) === '') {
                    $phar->buildFromDirectory($sourceRoot);
                    return;
                }

                $this->addDirectoryTreeToArchive($phar, $sourceRoot, $this->normalizeEntryName($entryName));
                return;
            }

            $entry = is_string($entryName) && trim($entryName) !== ''
                ? $this->normalizeEntryName($entryName)
                : trim(basename($sourcePath), '/');

            $this->ensureArchiveParentDirectories($phar, $entry);
            $phar->addFile($sourcePath, $entry);
        } catch (\Throwable $e) {
            @unlink($outputPath);
            throw new RuntimeException(
                'Failed to create TAR archive: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Creates a .tar archive from all files in a source directory.
     *
     * Symlinks are skipped. The caller supplies the output path including the
     * .tar extension. Use `compressDirectoryGz()` to produce a .tar.gz.
     *
     * @param string $sourceDir  Absolute path to the source directory.
     * @param string $outputPath Absolute path where the .tar file should be written.
     * @return void
     * @throws RuntimeException When the source directory is invalid or archiving fails.
     */
    public function compressDirectory(string $sourceDir, string $outputPath): void
    {
        $this->compressPath($sourceDir, $outputPath);
    }

    /**
     * Creates a .tar.gz archive from all files in a source directory.
     *
     * Builds an intermediate .tar first, then compresses it to .tar.gz via
     * PharData::compress(). The intermediate .tar is removed on success or failure.
     *
     * @param string $sourceDir  Absolute path to the source directory.
     * @param string $outputPath Absolute path where the .tar.gz file should be written.
     * @return void
     * @throws RuntimeException When the source directory is invalid or archiving fails.
     */
    public function compressDirectoryGz(string $sourceDir, string $outputPath): void
    {
        $this->compressPathGz($sourceDir, $outputPath);
    }

    /**
     * Creates a `.tar.gz` archive from one source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $outputPath Absolute path where the `.tar.gz` file should be written.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source path is invalid or archiving fails.
     */
    public function compressPathGz(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        $this->compressPathWithOuterLayer(
            $sourcePath,
            $outputPath,
            function (string $tarPath, string $archivePath) use ($entryName, $sourcePath): void {
                $this->compressPath($sourcePath, $tarPath, $entryName);
                (new Gz())->compress($tarPath, $archivePath);
            },
            'TAR.GZ'
        );
    }

    /**
     * Creates a .tar.bz2 archive from all files in a source directory.
     *
     * Builds a temporary `.tar` first, then compresses it through the dedicated
     * `Bz2` handler so TAR-family compression consistently flows through the
     * canonical single-file archive-type implementation.
     *
     * @param string $sourceDir Absolute path to the source directory.
     * @param string $outputPath Absolute path where the .tar.bz2 file should be written.
     * @return void
     * @throws RuntimeException When the source directory is invalid or archiving fails.
     */
    public function compressDirectoryBz2(string $sourceDir, string $outputPath): void
    {
        $this->compressPathBz2($sourceDir, $outputPath);
    }

    /**
     * Creates a `.tar.bz2` archive from one source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $outputPath Absolute path where the `.tar.bz2` file should be written.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source path is invalid or archiving fails.
     */
    public function compressPathBz2(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        $this->compressPathWithOuterLayer(
            $sourcePath,
            $outputPath,
            function (string $tarPath, string $archivePath) use ($entryName, $sourcePath): void {
                $this->compressPath($sourcePath, $tarPath, $entryName);
                (new Bz2())->compress($tarPath, $archivePath);
            },
            'TAR.BZ2'
        );
    }

    /**
     * Creates a .tar.xz archive from all files in a source directory.
     *
     * Builds a temporary `.tar` first, then compresses it through the dedicated
     * `Xz` handler so TAR-family compression stays aligned with the shared
     * archive-type entrypoint.
     *
     * @param string $sourceDir Absolute path to the source directory.
     * @param string $outputPath Absolute path where the .tar.xz file should be written.
     * @return void
     * @throws RuntimeException When the source directory is invalid or archiving fails.
     */
    public function compressDirectoryXz(string $sourceDir, string $outputPath): void
    {
        $this->compressPathXz($sourceDir, $outputPath);
    }

    /**
     * Creates a `.tar.xz` archive from one source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $outputPath Absolute path where the `.tar.xz` file should be written.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source path is invalid or archiving fails.
     */
    public function compressPathXz(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        $this->compressPathWithOuterLayer(
            $sourcePath,
            $outputPath,
            function (string $tarPath, string $archivePath) use ($entryName, $sourcePath): void {
                $this->compressPath($sourcePath, $tarPath, $entryName);
                (new Xz())->compress($tarPath, $archivePath);
            },
            'TAR.XZ'
        );
    }

    /**
     * Creates a .tar.zst archive from all files in a source directory.
     *
     * Builds a temporary `.tar` first, then compresses it through the dedicated
     * `Zst` handler so TAR-family compression stays aligned with the shared
     * archive-type entrypoint.
     *
     * @param string $sourceDir Absolute path to the source directory.
     * @param string $outputPath Absolute path where the .tar.zst file should be written.
     * @return void
     * @throws RuntimeException When the source directory is invalid or archiving fails.
     */
    public function compressDirectoryZst(string $sourceDir, string $outputPath): void
    {
        $this->compressPathZst($sourceDir, $outputPath);
    }

    /**
     * Creates a `.tar.zst` archive from one source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $outputPath Absolute path where the `.tar.zst` file should be written.
     * @param string|null $entryName Optional archive-internal root name override.
     * @return void
     * @throws RuntimeException When the source path is invalid or archiving fails.
     */
    public function compressPathZst(string $sourcePath, string $outputPath, ?string $entryName = null): void
    {
        $this->compressPathWithOuterLayer(
            $sourcePath,
            $outputPath,
            function (string $tarPath, string $archivePath) use ($entryName, $sourcePath): void {
                $this->compressPath($sourcePath, $tarPath, $entryName);
                (new Zst())->compress($tarPath, $archivePath);
            },
            'TAR.ZST'
        );
    }

    /**
     * Adds or replaces one file or directory in an existing `.tar` archive.
     *
     * @param string $archivePath Absolute path to the target `.tar` archive.
     * @param string $sourcePath Absolute path to the file or directory to add.
     * @param string|null $entryName Optional archive-internal destination path.
     * @return void
     * @throws RuntimeException When the source path is invalid or the archive update fails.
     */
    public function addPath(string $archivePath, string $sourcePath, ?string $entryName = null): void
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('TAR source path could not be resolved: ' . $sourcePath);
        }

        $archiveDirectory = dirname($archivePath);
        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new RuntimeException('Failed to create TAR archive directory.');
        }

        try {
            $phar = new PharData($archivePath);

            if (is_dir($sourcePath)) {
                $entry = is_string($entryName) && trim($entryName) !== ''
                    ? $this->normalizeEntryName($entryName)
                    : trim(basename($sourcePath), '/');

                $this->addDirectoryTreeToArchive($phar, $sourcePath, $entry);
                return;
            }

            $entry = is_string($entryName) && trim($entryName) !== ''
                ? $this->normalizeEntryName($entryName)
                : trim(basename($sourcePath), '/');

            $this->ensureArchiveParentDirectories($phar, $entry);
            $phar->addFile($sourcePath, $entry);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to update TAR archive: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Returns all entry names in a TAR (or .tar.gz) archive as an indexed array.
     *
     * @param string $archivePath Absolute path to the .tar or .tar.gz file.
     * @return array<int, string> List of entry names.
     * @throws RuntimeException When the archive cannot be opened.
     */
    public function listEntries(string $archivePath): array
    {
        try {
            $phar = new PharData($archivePath);
            $entries = [];
            foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file instanceof \PharFileInfo) {
                    $entryPath = $this->normalizePharEntryPath($file, $archivePath);
                    if ($entryPath !== '') {
                        if ($file->isDir()) {
                            $entryPath = rtrim($entryPath, '/') . '/';
                        }
                        $entries[] = $entryPath;
                    }
                }
            }

            return $entries;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to list entries in TAR archive "' . basename($archivePath) . '": ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Returns true when a TAR entry path is safe to extract.
     *
     * Rejects absolute paths, Windows drive-letter paths, null bytes, and any
     * path segment that is `.` or `..`.
     *
     * @param string $entryName Raw entry name from the TAR directory.
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
     * Builds a `.tar` first, then compresses it through one dedicated outer-layer handler.
     *
     * @param string $sourceRoot Canonical realpath() result for the source directory.
     * @param string $outputPath Absolute path of the final compressed TAR artifact.
     * @param callable(string, string): void $compressor Receives `$tarPath`, `$outputPath`.
     * @param string $label Human-readable archive label for exception messages.
     * @return void
     * @throws RuntimeException When intermediate TAR creation or outer compression fails.
     */
    private function compressPathWithOuterLayer(
        string $sourcePath,
        string $outputPath,
        callable $compressor,
        string $label
    ): void {
        $temporaryTarPath = $this->allocateTemporaryTarPath();

        try {
            if (!file_exists($sourcePath)) {
                throw new RuntimeException('TAR source path could not be resolved: ' . $sourcePath);
            }

            $compressor($temporaryTarPath, $outputPath);
        } catch (\Throwable $e) {
            @unlink($outputPath);
            throw new RuntimeException(
                'Failed to create ' . $label . ' archive: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            @unlink($temporaryTarPath);
        }
    }

    /**
     * Allocates one temporary `.tar` file path for staged TAR-family compression.
     *
     * @return string Absolute temporary `.tar` path.
     * @throws RuntimeException When the temporary file cannot be prepared.
     */
    private function allocateTemporaryTarPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rvn-tar-');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Failed to allocate temporary TAR path.');
        }

        // PharData expects to create a fresh archive file itself; leaving the
        // tempnam placeholder in place causes it to interpret an empty file as
        // a truncated TAR. Remove the placeholder and return a fresh suffix path.
        if (!@unlink($path)) {
            throw new RuntimeException('Failed to prepare temporary TAR path.');
        }

        return $path . '.tar';
    }

    /**
     * Normalizes one PharData entry to a relative archive-internal path.
     *
     * Phar exposes TAR entries as `phar:///absolute/archive/path.tar/entry/path`,
     * so callers need the prefix stripped before manifest resolution or targeted
     * extraction logic can reason about wrapper-directory depth.
     *
     * @param \PharFileInfo $file One entry returned from the Phar iterator.
     * @param string $archivePath Absolute path to the source archive.
     * @return string Relative archive entry path.
     */
    private function normalizePharEntryPath(\PharFileInfo $file, string $archivePath): string
    {
        $entryPath = str_replace('\\', '/', $file->getPathName());
        $candidatePrefixes = [
            'phar://' . str_replace('\\', '/', $archivePath) . '/',
        ];

        $resolvedArchivePath = realpath($archivePath);
        if (is_string($resolvedArchivePath) && $resolvedArchivePath !== '') {
            $candidatePrefixes[] = 'phar://' . str_replace('\\', '/', $resolvedArchivePath) . '/';
        }

        foreach ($candidatePrefixes as $prefix) {
            if (str_starts_with($entryPath, $prefix)) {
                return ltrim(substr($entryPath, strlen($prefix)), '/');
            }
        }

        return ltrim($file->getFilename(), '/');
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
            throw new RuntimeException('Unsafe TAR entry path: ' . $entryName);
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
     * Adds one directory tree to an open TAR archive under a given root path.
     *
     * @param PharData $phar Open TAR archive handle.
     * @param string $sourceDir Absolute source directory path.
     * @param string $entryRoot Archive-internal destination root.
     * @return void
     * @throws RuntimeException When one directory or file cannot be added.
     */
    private function addDirectoryTreeToArchive(PharData $phar, string $sourceDir, string $entryRoot): void
    {
        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('TAR source directory could not be resolved: ' . $sourceDir);
        }

        $phar->addEmptyDir($entryRoot);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $absolutePath = $item->getPathname();
            $relativePath = ltrim(substr($absolutePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $archivePath = $entryRoot . '/' . str_replace('\\', '/', $relativePath);
            if ($item->isDir()) {
                $phar->addEmptyDir($archivePath);
                continue;
            }

            $this->ensureArchiveParentDirectories($phar, $archivePath);
            $phar->addFile($absolutePath, $archivePath);
        }
    }

    /**
     * Ensures parent directories exist inside the TAR archive before adding a path.
     *
     * @param PharData $phar Open TAR archive handle.
     * @param string $entryPath Archive-internal file path.
     * @return void
     */
    private function ensureArchiveParentDirectories(PharData $phar, string $entryPath): void
    {
        $directory = trim((string) pathinfo($entryPath, PATHINFO_DIRNAME), '.');
        if ($directory === '') {
            return;
        }

        $segments = explode('/', $directory);
        $current = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $current = $current === '' ? $segment : $current . '/' . $segment;

            try {
                $phar->addEmptyDir($current);
            } catch (\Throwable) {
                // PharData throws when the directory already exists; the archive
                // only needs the directory to be present, so duplicates are safe.
            }
        }
    }

    /**
     * Writes one extracted TAR entry to disk.
     *
     * @param string $targetPath Absolute output path.
     * @param string $contents Extracted file contents.
     * @return void
     * @throws RuntimeException When the output cannot be written.
     */
    private function writeExtractedEntry(string $targetPath, string $contents): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create directory for extracted TAR entry.');
        }

        if (file_put_contents($targetPath, $contents) === false) {
            throw new RuntimeException('Failed to write extracted TAR entry to "' . $targetPath . '".');
        }
    }
}
