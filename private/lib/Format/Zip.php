<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Zip.php
 * ZIP archive handler — extract, inspect, and build ZIP archives.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use RuntimeException;
use ZipArchive;

/**
 * Canonical ZIP archive handler for Raven core and extensions.
 *
 * Handles safe extraction, whole-archive creation, selective file/folder
 * extraction, path addition, and manifest slug reading. All extraction
 * operations validate entry paths against zip-slip traversal before writing
 * any file.
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
        // Empty entry names are invalid extraction targets.
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

        // Keep archive handles balanced even when validation or extraction fails.
        try {
            // Empty archives are treated as invalid package payloads.
            if ($zip->numFiles < 1) {
                throw new RuntimeException('ZIP archive is empty.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                // Every entry must validate before any filesystem writes occur.
                if (!is_string($name) || !$this->isSafeEntryPath($name)) {
                    throw new RuntimeException('ZIP archive contains an unsafe entry path.');
                }
            }

            // Delegate final write-out to ZipArchive once pre-validation has passed.
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
        $entry = $this->normalizeEntryName($entryName);
        $zip = $this->open($archivePath);

        // Close the archive on both success and error paths.
        try {
            $contents = $zip->getFromName($entry);
            // A missing entry must fail explicitly so callers do not create empty target files.
            if ($contents === false) {
                throw new RuntimeException('Entry "' . $entry . '" not found in ZIP archive.');
            }

            $this->writeExtractedEntry($targetPath, $contents, 'ZIP');
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts one named ZIP directory into a target directory.
     *
     * @param string $archivePath Absolute path to the source ZIP file.
     * @param string $entryName ZIP-internal directory path to extract.
     * @param string $targetDir Absolute path that should receive the directory contents.
     * @return void
     * @throws RuntimeException When the directory is unsafe, missing, or extraction fails.
     */
    public function extractDir(string $archivePath, string $entryName, string $targetDir): void
    {
        $directory = $this->normalizeDirEntry($entryName);
        $zip = $this->open($archivePath);

        // Track matching members while guaranteeing the archive closes at the end.
        try {
            $matches = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                // Ignore invalid names instead of extracting them into caller paths.
                if (!is_string($name) || !$this->isSafeEntryPath($name)) {
                    continue;
                }

                $normalized = rtrim(str_replace('\\', '/', $name), '/');
                // Include exact directory entries when archives store explicit folder markers.
                if ($normalized === trim($directory, '/')) {
                    $matches[] = $normalized . '/';
                    continue;
                }

                // Include descendants so the full requested subtree is restored.
                if (str_starts_with(str_replace('\\', '/', $name), $directory)) {
                    $matches[] = str_replace('\\', '/', $name);
                }
            }

            // Fail when no entries map to the requested subtree.
            if ($matches === []) {
                throw new RuntimeException('Directory "' . trim($directory, '/') . '" not found in ZIP archive.');
            }

            // Deduplicate because some archives include both dir markers and repeated paths.
            foreach (array_values(array_unique($matches)) as $match) {
                $relative = ltrim(substr($match, strlen($directory)), '/');
                // Skip the directory root marker itself; only children map to output paths.
                if ($relative === '') {
                    continue;
                }

                $targetPath = $targetDir . '/' . $relative;
                // Preserve directory entries so empty subfolders are recreated on disk.
                if (str_ends_with($match, '/')) {
                    // Create nested folders lazily as directory markers are encountered.
                    if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                        throw new RuntimeException('Failed to create directory for extracted ZIP entry.');
                    }
                    continue;
                }

                $contents = $zip->getFromName($match);
                // File entries must resolve to bytes before any write attempt.
                if ($contents === false) {
                    throw new RuntimeException('Entry "' . $match . '" not found in ZIP archive.');
                }

                $this->writeExtractedEntry($targetPath, $contents, 'ZIP');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Creates a ZIP archive from one source file or directory.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $entryName Preferred archive-internal root name.
     * @param string $outputPath Absolute path where the ZIP file should be created.
     * @return void
     * @throws RuntimeException When the source path is invalid or the archive cannot be built.
     */
    public function compressPath(string $sourcePath, string $entryName, string $outputPath): void
    {
        $this->assertAvailable();

        // Source presence is validated before archive creation to avoid empty output artifacts.
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('ZIP source path could not be resolved: ' . $sourcePath);
        }

        $outputDirectory = dirname($outputPath);
        // Ensure output parent directories exist before creating the archive file.
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Failed to create ZIP output directory.');
        }

        $zip = new ZipArchive();
        $result = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        // ZipArchive::open returns non-true status codes when creation cannot proceed.
        if ($result !== true) {
            throw new RuntimeException('Failed to create ZIP archive at: ' . $outputPath);
        }

        // Delete partially built archives when path population fails mid-stream.
        try {
            $this->addPathToArchive($zip, $sourcePath, $entryName);
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($outputPath);
            throw new RuntimeException(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to build ZIP archive.',
                0,
                $exception
            );
        }

        $zip->close();
    }

    /**
     * Creates a ZIP archive from all files in a source directory.
     *
     * Adds each file under an optional archive root prefix. Symlinks are skipped.
     * This remains the canonical directory-focused helper used by package export
     * flows, while `compressPath()` now covers generic file-or-directory creation.
     *
     * @param string $sourceDir   Absolute path to the source directory.
     * @param string $archiveRoot Optional directory name to use as the archive root prefix.
     * @param string $outputPath  Absolute path where the ZIP file should be created.
     * @return void
     * @throws RuntimeException When the source directory cannot be resolved, the
     *                          archive cannot be initialized, or a file fails to add.
     */
    public function compressDir(string $sourceDir, string $archiveRoot, string $outputPath): void
    {
        $this->compressPath($sourceDir, $archiveRoot, $outputPath);
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
        $this->addPath($archivePath, $sourcePath, $entryName);
    }

    /**
     * Adds or replaces one file or directory in an existing ZIP archive.
     *
     * @param string $archivePath Absolute path to the ZIP archive to modify or create.
     * @param string $sourcePath Absolute path to the file or directory to add.
     * @param string $entryName ZIP-internal destination path.
     * @return void
     * @throws RuntimeException When the archive cannot be opened or the path cannot be added.
     */
    public function addPath(string $archivePath, string $sourcePath, string $entryName): void
    {
        $this->assertAvailable();

        // Reject unresolved sources before attempting to open or mutate the archive.
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('ZIP source path not found: ' . $sourcePath);
        }

        $archiveDirectory = dirname($archivePath);
        // Create archive parents for first-write cases where the zip file does not exist yet.
        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new RuntimeException('Failed to create ZIP archive directory.');
        }

        $zip = new ZipArchive();
        $flags = is_file($archivePath) ? 0 : ZipArchive::CREATE;
        // Surface open failures immediately so callers can report archive write issues.
        if ($zip->open($archivePath, $flags) !== true) {
            throw new RuntimeException('Failed to open ZIP archive for writing: ' . $archivePath);
        }

        // Always close the archive handle, even when adding paths throws.
        try {
            $this->addPathToArchive($zip, $sourcePath, $entryName);
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

        // Keep read handles balanced across all early returns and errors.
        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                // Skip non-string slots returned by ZipArchive internals.
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
        public function manifestSlug(string $archivePath, string $manifestFilename, int $maxSlugLength): ?string
    {
        $filename = strtolower(trim($manifestFilename));
        // Empty manifest names cannot be matched against archive entries.
        if ($filename === '') {
            return null;
        }

        $zip = $this->open($archivePath);

        // Search candidate manifests while guaranteeing the archive handle closes.
        try {
            $slugPattern = '/^[a-z0-9][a-z0-9_-]{0,' . max(0, $maxSlugLength) . '}$/';
            $indexes = $this->manifestEntryIndexes($zip, $filename);

            // Candidate indexes are already ordered by preferred manifest depth.
            foreach ($indexes as $index) {
                $raw = $zip->getFromIndex($index);
                // Ignore missing or blank manifest payloads.
                if (!is_string($raw) || trim($raw) === '') {
                    continue;
                }

                /** @var mixed $decoded */
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $slug = strtolower(trim((string) ($decoded['slug'] ?? '')));
                // Return the first slug that matches Raven's manifest slug contract.
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
        // Reading flows require a successfully opened archive handle.
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Failed to open ZIP archive: ' . $archivePath);
        }

        return $zip;
    }

    /**
     * Collects candidate manifest entry indexes in preferred lookup order.
     *
     * Returns root-level manifest indexes before one-directory-deep indexes so
     * that `manifestSlug()` can prefer the root manifest when both exist.
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
            // Ignore invalid or unsafe names before manifest filename matching.
            if (!is_string($name) || !$this->isSafeEntryPath($name)) {
                continue;
            }

            $normalized = trim(str_replace('\\', '/', $name), '/');
            // Candidate entries must resolve to the requested manifest basename.
            if ($normalized === '' || strtolower((string) pathinfo($normalized, PATHINFO_BASENAME)) !== $manifestFile) {
                continue;
            }

            $dir = trim((string) pathinfo($normalized, PATHINFO_DIRNAME), '.');
            $depth = $dir === '' ? 0 : substr_count($dir, '/') + 1;
            // Only root-level or one-wrapper manifests are supported by contract.
            if ($depth > 1) {
                continue;
            }

            // Root manifests are preferred over wrapped manifests during lookup.
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
        // Guard every ZIP entrypoint behind extension availability checks.
        if (!$this->isAvailable()) {
            throw new RuntimeException('PHP zip extension is not available.');
        }
    }

    /**
     * Normalizes one archive-internal entry path and rejects traversal.
     *
     * @param string $entryName Archive-internal path candidate.
     * @return string Safe normalized entry path.
     * @throws RuntimeException When the path is unsafe.
     */
    private function normalizeEntryName(string $entryName): string
    {
        $normalized = trim(str_replace('\\', '/', $entryName), '/');
        // Re-run path safety checks after normalization to block traversal aliases.
        if (!$this->isSafeEntryPath($normalized)) {
            throw new RuntimeException('Unsafe ZIP entry path: ' . $entryName);
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
     * Adds one file or directory path to an open ZIP archive.
     *
     * @param ZipArchive $zip Open ZIP archive handle.
     * @param string $sourcePath Absolute path to the file or directory to add.
     * @param string $entryName ZIP-internal destination path.
     * @return void
     * @throws RuntimeException When the entry path is unsafe or the add fails.
     */
    private function addPathToArchive(ZipArchive $zip, string $sourcePath, string $entryName): void
    {
        $entry = $this->normalizeEntryName($entryName);

        // Directory sources are expanded recursively into archive members.
        if (is_dir($sourcePath)) {
            $this->addDirTree($zip, $sourcePath, $entry);
            return;
        }

        // File sources map to one target entry and must succeed atomically.
        if (!$zip->addFile($sourcePath, $entry)) {
            throw new RuntimeException('Failed to add file "' . $entry . '" to ZIP archive.');
        }
    }

    /**
     * Adds one directory tree to an open ZIP archive under a given root path.
     *
     * @param ZipArchive $zip Open ZIP archive handle.
     * @param string $sourceDir Absolute path to the source directory.
     * @param string $entryRoot ZIP-internal directory root.
     * @return void
     * @throws RuntimeException When a directory or file cannot be added.
     */
    private function addDirTree(ZipArchive $zip, string $sourceDir, string $entryRoot): void
    {
        $sourceRoot = realpath($sourceDir);
        // Realpath resolution guarantees stable prefixes while iterating child entries.
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('ZIP source directory could not be resolved: ' . $sourceDir);
        }

        // Create the archive root folder first so relative children have a parent container.
        if (!$zip->addEmptyDir($entryRoot)) {
            throw new RuntimeException('Failed to initialize ZIP archive root directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Add directories/files in one pass while skipping symlinks for package safety.
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $absolute = $item->getPathname();
            $relative = ltrim(substr($absolute, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            // Ignore the source root pseudo-entry because the archive root already exists.
            if ($relative === '') {
                continue;
            }

            $zipPath = $entryRoot . '/' . str_replace('\\', '/', $relative);

            // Directory nodes become explicit ZIP directory entries.
            if ($item->isDir()) {
                // Nested folder creation failures should abort the archive write.
                if (!$zip->addEmptyDir($zipPath)) {
                    throw new RuntimeException('Failed to add directory "' . $relative . '" to ZIP archive.');
                }
                continue;
            }

            // Non-directory nodes are copied as file entries under the computed ZIP path.
            if (!$zip->addFile($absolute, $zipPath)) {
                throw new RuntimeException('Failed to add file "' . $relative . '" to ZIP archive.');
            }
        }
    }

    /**
     * Writes one extracted archive entry to disk.
     *
     * @param string $targetPath Absolute output path.
     * @param string $contents Extracted file contents.
     * @param string $label Human-readable archive label for errors.
     * @return void
     * @throws RuntimeException When the output cannot be written.
     */
    private function writeExtractedEntry(string $targetPath, string $contents, string $label): void
    {
        $directory = dirname($targetPath);
        // Create missing destination parents before writing extracted file content.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create directory for extracted ' . $label . ' entry.');
        }

        // Fail hard on write errors so extraction callers never assume success.
        if (file_put_contents($targetPath, $contents) === false) {
            throw new RuntimeException('Failed to write extracted ' . $label . ' entry to "' . $targetPath . '".');
        }
    }
}
