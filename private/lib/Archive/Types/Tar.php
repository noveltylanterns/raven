<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Types/Tar.php
 * TAR archive handler — extract, inspect, and build .tar and .tar.gz archives.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive\Types;

use PharData;
use RuntimeException;

/**
 * Canonical TAR archive handler for Raven core and extensions.
 *
 * Handles safe extraction, directory compression, and single-entry extraction
 * for .tar and .tar.gz archives via PHP's PharData. For .tar.bz2, .tar.xz, and
 * .tar.zst archives, decompress the outer layer first with the appropriate
 * Bz2/Xz/Zst handler, then pass the resulting .tar to this class.
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
        if (!$this->isSafeEntryPath($entryName)) {
            throw new RuntimeException('Unsafe TAR entry path: ' . $entryName);
        }

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
        if (!isset($phar[$entryName])) {
            throw new RuntimeException('Entry "' . $entryName . '" not found in TAR archive.');
        }

        $contents = file_get_contents('phar://' . $archivePath . '/' . $entryName);
        if ($contents === false) {
            throw new RuntimeException('Failed to read entry "' . $entryName . '" from TAR archive.');
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory for extracted TAR entry.');
        }

        if (file_put_contents($targetPath, $contents) === false) {
            throw new RuntimeException('Failed to write extracted TAR entry to "' . $targetPath . '".');
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
        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('TAR source directory could not be resolved: ' . $sourceDir);
        }

        try {
            $phar = new PharData($outputPath);
            $phar->buildFromDirectory($sourceRoot);
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
        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('TAR source directory could not be resolved: ' . $sourceDir);
        }

        $this->compressDirectoryWithOuterLayer(
            $sourceRoot,
            $outputPath,
            static function (string $tarPath, string $archivePath): void {
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
        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('TAR source directory could not be resolved: ' . $sourceDir);
        }

        $this->compressDirectoryWithOuterLayer(
            $sourceRoot,
            $outputPath,
            static function (string $tarPath, string $archivePath): void {
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
        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('TAR source directory could not be resolved: ' . $sourceDir);
        }

        $this->compressDirectoryWithOuterLayer(
            $sourceRoot,
            $outputPath,
            static function (string $tarPath, string $archivePath): void {
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
        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('TAR source directory could not be resolved: ' . $sourceDir);
        }

        $this->compressDirectoryWithOuterLayer(
            $sourceRoot,
            $outputPath,
            static function (string $tarPath, string $archivePath): void {
                (new Zst())->compress($tarPath, $archivePath);
            },
            'TAR.ZST'
        );
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
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                if ($file instanceof \PharFileInfo) {
                    $entryPath = $this->normalizePharEntryPath($file, $archivePath);
                    if ($entryPath !== '') {
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
    private function compressDirectoryWithOuterLayer(
        string $sourceRoot,
        string $outputPath,
        callable $compressor,
        string $label
    ): void {
        $temporaryTarPath = $this->allocateTemporaryTarPath();

        try {
            $this->compressDirectory($sourceRoot, $temporaryTarPath);
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
}
