<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Types/Zip.php
 * ZIP archive handler — extract, inspect, and build ZIP archives.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive\Types;

use RuntimeException;
use ZipArchive;

/**
 * Canonical ZIP archive handler for Raven core and extensions.
 *
 * Handles safe extraction, whole-directory compression, single-file extraction,
 * file addition, and manifest slug reading. All extraction operations validate
 * entry paths against zip-slip traversal before writing any file.
 */
final class Zip
{
    /**
     * Returns true when the PHP zip extension is loaded and usable.
     *
     * @return bool True when `ZipArchive` is available.
     */
    public function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * Returns true when a ZIP entry path is safe to extract.
     *
     * Rejects absolute paths, Windows drive-letter paths, null bytes, and any
     * path segment that is `.` or `..` to prevent zip-slip traversal attacks.
     *
     * @param string $entryName Raw entry name from the ZIP directory.
     * @return bool True when the path is safe for extraction.
     */
    public function isSafeEntryPath(string $entryName): bool
    {
        $path = str_replace('\\', '/', trim($entryName));
        if ($path === '') {
            return false;
        }

        // Reject absolute paths and Windows drive-letter prefixes.
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }

        // Reject null bytes anywhere in the path.
        if (str_contains($path, "\0")) {
            return false;
        }

        // Reject path traversal segments.
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
        }

        // Re-check explicitly to catch leading/trailing dots used as traversal.
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Extracts all entries from a ZIP archive into a target directory.
     *
     * Validates every entry path for zip-slip traversal before extraction begins.
     * Throws on any unsafe entry, empty archive, or extraction failure.
     *
     * @param string $archivePath Absolute path to the source ZIP file.
     * @param string $targetDir   Absolute path to the destination directory.
     * @return void
     * @throws RuntimeException When the archive cannot be opened, contains unsafe
     *                          paths, is empty, or extraction fails.
     */
    public function extractTo(string $archivePath, string $targetDir): void
    {
        $zip = $this->open($archivePath);

        try {
            if ($zip->numFiles < 1) {
                throw new RuntimeException('ZIP archive is empty.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || !$this->isSafeEntryPath($name)) {
                    throw new RuntimeException('ZIP archive contains an unsafe entry path.');
                }
            }

            if (!$zip->extractTo($targetDir)) {
                throw new RuntimeException('Failed to extract ZIP archive to target directory.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts a single named entry from a ZIP archive to a target file path.
     *
     * Validates the entry path for zip-slip traversal. Creates intermediate
     * directories as needed.
     *
     * @param string $archivePath Absolute path to the source ZIP file.
     * @param string $entryName   ZIP-internal entry name to extract.
     * @param string $targetPath  Absolute path where the extracted file should land.
     * @return void
     * @throws RuntimeException When the archive cannot be opened, the entry is not
     *                          found, the path is unsafe, or the write fails.
     */
    public function extractFile(string $archivePath, string $entryName, string $targetPath): void
    {
        if (!$this->isSafeEntryPath($entryName)) {
            throw new RuntimeException('Unsafe ZIP entry path: ' . $entryName);
        }

        $zip = $this->open($archivePath);

        try {
            $contents = $zip->getFromName($entryName);
            if ($contents === false) {
                throw new RuntimeException('Entry "' . $entryName . '" not found in ZIP archive.');
            }

            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory for extracted file.');
            }

            if (file_put_contents($targetPath, $contents) === false) {
                throw new RuntimeException('Failed to write extracted file to "' . $targetPath . '".');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Creates a ZIP archive from all files in a source directory.
     *
     * Adds each file under an optional archive root prefix. Symlinks are skipped.
     * Writes to a temporary file first, then returns the temporary path; the caller
     * is responsible for moving or deleting it.
     *
     * @param string $sourceDir   Absolute path to the source directory.
     * @param string $archiveRoot Optional directory name to use as the archive root prefix.
     * @param string $outputPath  Absolute path where the ZIP file should be created.
     * @return void
     * @throws RuntimeException When the source directory cannot be resolved, the
     *                          archive cannot be initialized, or a file fails to add.
     */
    public function compressDirectory(string $sourceDir, string $archiveRoot, string $outputPath): void
    {
        $this->assertAvailable();

        $sourceRoot = realpath($sourceDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('ZIP source directory could not be resolved: ' . $sourceDir);
        }

        $root = trim(preg_replace('/[^a-z0-9._-]+/i', '-', $archiveRoot) ?? '', '-_.');
        if ($root === '') {
            $root = 'archive';
        }

        $zip = new ZipArchive();
        $result = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('Failed to create ZIP archive at: ' . $outputPath);
        }

        try {
            if (!$zip->addEmptyDir($root)) {
                throw new RuntimeException('Failed to initialize ZIP archive root directory.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                if ($item->isLink()) {
                    continue;
                }

                $absolute = $item->getPathname();
                $relative = ltrim(substr($absolute, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
                if ($relative === '') {
                    continue;
                }

                $zipPath = $root . '/' . str_replace('\\', '/', $relative);

                if ($item->isDir()) {
                    if (!$zip->addEmptyDir($zipPath)) {
                        throw new RuntimeException('Failed to add directory "' . $relative . '" to ZIP archive.');
                    }
                    continue;
                }

                if (!$zip->addFile($absolute, $zipPath)) {
                    throw new RuntimeException('Failed to add file "' . $relative . '" to ZIP archive.');
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($outputPath);
            throw new RuntimeException($e->getMessage() !== '' ? $e->getMessage() : 'Failed to build ZIP archive.');
        }

        $zip->close();
    }

    /**
     * Adds or replaces a single file in an existing ZIP archive.
     *
     * Creates the archive if it does not yet exist.
     *
     * @param string $archivePath Absolute path to the ZIP file to modify or create.
     * @param string $sourcePath  Absolute path to the file to add.
     * @param string $entryName   ZIP-internal entry name for the file.
     * @return void
     * @throws RuntimeException When the archive cannot be opened or the file cannot be added.
     */
    public function addFile(string $archivePath, string $sourcePath, string $entryName): void
    {
        $this->assertAvailable();

        if (!$this->isSafeEntryPath($entryName)) {
            throw new RuntimeException('Unsafe ZIP entry name: ' . $entryName);
        }

        if (!is_file($sourcePath)) {
            throw new RuntimeException('Source file does not exist: ' . $sourcePath);
        }

        $zip = new ZipArchive();
        $flags = is_file($archivePath) ? 0 : ZipArchive::CREATE;
        if ($zip->open($archivePath, $flags) !== true) {
            throw new RuntimeException('Failed to open ZIP archive for writing: ' . $archivePath);
        }

        try {
            if (!$zip->addFile($sourcePath, $entryName)) {
                throw new RuntimeException('Failed to add file "' . $entryName . '" to ZIP archive.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Returns all entry names in a ZIP archive as an indexed array.
     *
     * @param string $archivePath Absolute path to the ZIP file.
     * @return array<int, string> List of entry names.
     * @throws RuntimeException When the archive cannot be opened.
     */
    public function listEntries(string $archivePath): array
    {
        $zip = $this->open($archivePath);

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name)) {
                    $entries[] = $name;
                }
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * Reads a slug value from a manifest JSON file inside a ZIP archive.
     *
     * Supports manifests at the archive root or one wrapper-directory deep.
     * Returns the first valid slug found in preferred depth order (root first).
     *
     * @param string $archivePath      Absolute path to the ZIP file.
     * @param string $manifestFilename Manifest file basename to search for (e.g. `ext.json`).
     * @param int    $maxSlugLength    Maximum allowed slug length for the regex check.
     * @return string|null The slug string, or null if none found or valid.
     */
    public function slugFromManifest(string $archivePath, string $manifestFilename, int $maxSlugLength): ?string
    {
        $filename = strtolower(trim($manifestFilename));
        if ($filename === '') {
            return null;
        }

        $zip = $this->open($archivePath);

        try {
            $slugPattern = '/^[a-z0-9][a-z0-9_-]{0,' . max(0, $maxSlugLength) . '}$/';
            $indexes = $this->manifestEntryIndexes($zip, $filename);

            foreach ($indexes as $index) {
                $raw = $zip->getFromIndex($index);
                if (!is_string($raw) || trim($raw) === '') {
                    continue;
                }

                /** @var mixed $decoded */
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $slug = strtolower(trim((string) ($decoded['slug'] ?? '')));
                if ($slug !== '' && preg_match($slugPattern, $slug) === 1) {
                    return $slug;
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * Opens a ZIP archive for reading, throwing on failure.
     *
     * @param string $archivePath Absolute path to the ZIP file.
     * @return ZipArchive Opened archive handle.
     * @throws RuntimeException When the archive cannot be opened.
     */
    private function open(string $archivePath): ZipArchive
    {
        $this->assertAvailable();

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Failed to open ZIP archive: ' . $archivePath);
        }

        return $zip;
    }

    /**
     * Collects candidate manifest entry indexes in preferred lookup order.
     *
     * Returns root-level manifest indexes before one-directory-deep indexes so
     * that `slugFromManifest` can prefer the root manifest when both exist.
     *
     * @param ZipArchive $zip            Open ZIP archive handle.
     * @param string     $manifestFile   Lowercase manifest filename to search for.
     * @return array<int, int> List of entry indexes in preferred order.
     */
    private function manifestEntryIndexes(ZipArchive $zip, string $manifestFile): array
    {
        $root = [];
        $wrapped = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || !$this->isSafeEntryPath($name)) {
                continue;
            }

            $normalized = trim(str_replace('\\', '/', $name), '/');
            if ($normalized === '' || strtolower((string) pathinfo($normalized, PATHINFO_BASENAME)) !== $manifestFile) {
                continue;
            }

            $dir = trim((string) pathinfo($normalized, PATHINFO_DIRNAME), '.');
            $depth = $dir === '' ? 0 : substr_count($dir, '/') + 1;
            if ($depth > 1) {
                continue;
            }

            if ($depth === 0) {
                $root[] = $i;
            } else {
                $wrapped[] = $i;
            }
        }

        return [...$root, ...$wrapped];
    }

    /**
     * Throws when the PHP zip extension is unavailable.
     *
     * @return void
     * @throws RuntimeException When `ZipArchive` cannot be instantiated on this system.
     */
    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('PHP zip extension is not available.');
        }
    }
}
