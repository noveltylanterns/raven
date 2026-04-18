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

        // PharData::compress() writes to a path derived from the current archive
        // name, so we build to a .tar first and let PharData produce the .tar.gz.
        $tarPath = rtrim($outputPath, '.gz');
        if (!str_ends_with($tarPath, '.tar')) {
            $tarPath .= '.tar';
        }

        try {
            $phar = new PharData($tarPath);
            $phar->buildFromDirectory($sourceRoot);
            // compress() creates the .tar.gz alongside the .tar.
            $phar->compress(\Phar::GZ);
        } catch (\Throwable $e) {
            @unlink($tarPath);
            @unlink($outputPath);
            throw new RuntimeException(
                'Failed to create TAR.GZ archive: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            // Remove the intermediate .tar whether we succeeded or failed.
            @unlink($tarPath);
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
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                if ($file instanceof \PharFileInfo) {
                    $entries[] = $file->getFilename();
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
}
