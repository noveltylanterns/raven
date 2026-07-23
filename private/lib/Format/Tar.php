<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Tar.php
 * TAR archive handler — extract, inspect, and build TAR-family archives.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

use PharData;
use Raven\Lib\Security\SymlinkGuard;
use RuntimeException;

/**
 * Canonical TAR archive handler for Raven core and extensions.
 *
 * Extraction and listing use PHP's PharData for the shared read surface, while
 * archive creation/update use the system `tar` binary so exported tarballs keep
 * source modification times intact. For `.tar.gz`, `.tar.bz2`, `.tar.xz`, and
 * `.tar.zst`, the higher-level archive forwarders still peel off or apply the
 * outer single-file compression layer.
 *
 * Path traversal safety: PharData rejects `..` segments natively during
 * extraction. For single-file extraction, entry names are validated before use.
 */
final class Tar
{
    /** Absolute or PATH-relative path to the tar binary. */
    private string $binary;

    /**
     * @param string $binary Path to the tar binary; defaults to `tar`.
     */
    public function __construct(string $binary = 'tar')
    {
        $this->binary = $binary !== '' ? $binary : 'tar';
    }

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
        // Keep archive reads and direct extraction writes inside physical paths.
        SymlinkGuard::assertSymlinkFreePath($archivePath, 'TAR archive path');
        SymlinkGuard::assertSymlinkFreePath($targetDir, 'TAR extraction directory');

        // Wrap PharData open/extract errors in a Raven-format runtime exception.
        $temporaryDirectory = $this->tempDir();
        try {
            $this->withPharArchive($archivePath, function (string $pharArchivePath) use ($targetDir, $subDir, $temporaryDirectory): void {
                $phar = new PharData($pharArchivePath);
                $phar->extractTo($temporaryDirectory, $subDir, true);

                // The direct PharData target can contain linked members or follow a
                // pre-existing child link, so rebuild the result through a clean tree.
                if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                    throw new RuntimeException('Failed to recreate TAR extraction target directory.');
                }
                $this->copyTree($temporaryDirectory, $targetDir);
            });
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to extract TAR archive "' . basename($archivePath) . '": ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->deleteTree($temporaryDirectory);
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
        // Reject symlinked archive inputs and output paths before reading/writing.
        SymlinkGuard::assertSymlinkFreePath($archivePath, 'TAR archive path');
        SymlinkGuard::assertSymlinkFreePath($targetPath, 'TAR extraction path');

        $entry = $this->normalizeEntryName($entryName);

        // Wrap PharData open errors in a Raven-format runtime exception.
        try {
            $this->withPharArchive($archivePath, function (string $pharArchivePath) use ($entry, $targetPath): void {
                $phar = new PharData($pharArchivePath);
                // PharData exposes entries via ArrayAccess keyed by entry name.
                if (!isset($phar[$entry])) {
                    throw new RuntimeException('Entry "' . $entry . '" not found in TAR archive.');
                }

                if (isset($this->symlinkEntries($pharArchivePath)[$entry])) {
                    throw new RuntimeException('Entry "' . $entry . '" is a symbolic link and cannot be extracted directly.');
                }

                $contents = file_get_contents('phar://' . $pharArchivePath . '/' . $entry);
                // Entry content must be readable through the phar stream wrapper.
                if ($contents === false) {
                    throw new RuntimeException('Failed to read entry "' . $entry . '" from TAR archive.');
                }

                $this->writeExtractedEntry($targetPath, $contents);
            });
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to open TAR archive "' . basename($archivePath) . '": ' . $e->getMessage(),
                0,
                $e
            );
        }

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
    public function extractDir(string $archivePath, string $entryName, string $targetDir): void
    {
        // Reject symlinked archive inputs and directory destinations before traversal.
        SymlinkGuard::assertSymlinkFreePath($archivePath, 'TAR archive path');
        SymlinkGuard::assertSymlinkFreePath($targetDir, 'TAR extraction directory');

        $directory = $this->normalizeDirEntry($entryName);
        $matches = [];

        // Keep entries that match the exact directory marker or its descendants.
        foreach ($this->listEntries($archivePath) as $entry) {
            $normalized = rtrim($entry, '/');
            // Preserve explicit directory marker entries with trailing slash.
            if ($normalized === trim($directory, '/')) {
                $matches[] = $normalized . '/';
                continue;
            }

            // Keep descendant entries under the requested directory prefix.
            if (str_starts_with($entry, $directory)) {
                $matches[] = $entry;
            }
        }

        // Requested directory prefix must resolve to at least one archive entry.
        if ($matches === []) {
            throw new RuntimeException('Directory "' . trim($directory, '/') . '" not found in TAR archive.');
        }

        // Materialize directory entries and file entries under the target directory.
        foreach (array_values(array_unique($matches)) as $match) {
            $relative = ltrim(substr($match, strlen($directory)), '/');
            // Skip the directory root marker itself.
            if ($relative === '') {
                continue;
            }

            $targetPath = $targetDir . '/' . $relative;
            // Skip linked child destinations while continuing other entries.
            if (!SymlinkGuard::isSymlinkFreePath($targetPath)) {
                continue;
            }
            // Ensure empty directory entries are created.
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
        // Reject symlinked source and output paths before TAR resolution can follow them.
        SymlinkGuard::assertSymlinkFreePath($sourcePath, 'TAR source path');
        SymlinkGuard::assertSymlinkFreePath($outputPath, 'TAR output path');

        // Source path must exist before staging/compression.
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('TAR source path could not be resolved: ' . $sourcePath);
        }

        $outputDirectory = dirname($outputPath);
        // Ensure output directory exists before writing archive.
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Failed to create TAR output directory.');
        }

        @unlink($outputPath);

        $staging = $this->stagePath($sourcePath, $entryName);

        // Run tar create command from staging tree; clean up on failure.
        try {
            $result = $this->runBinary([
                '-cf',
                $outputPath,
                '-C',
                $staging['directory'],
                $staging['target'],
            ]);
            // Non-zero tar exit status is surfaced as a runtime exception.
            if (!$result['ok']) {
                throw new RuntimeException($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            }
        } catch (\Throwable $e) {
            @unlink($outputPath);
            throw new RuntimeException(
                'Failed to create TAR archive: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->deleteTree($staging['directory']);
        }
    }

    /**
     * Creates a .tar archive from all files in a source directory.
     *
     * Symlinks are skipped. The caller supplies the output path including the
     * .tar extension. Use `compressDirGz()` to produce a .tar.gz.
     *
     * @param string $sourceDir  Absolute path to the source directory.
     * @param string $outputPath Absolute path where the .tar file should be written.
     * @return void
     * @throws RuntimeException When the source directory is invalid or archiving fails.
     */
    public function compressDir(string $sourceDir, string $outputPath): void
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
    public function compressDirGz(string $sourceDir, string $outputPath): void
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
        $this->compressOuter(
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
    public function compressDirBz2(string $sourceDir, string $outputPath): void
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
        $this->compressOuter(
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
    public function compressDirXz(string $sourceDir, string $outputPath): void
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
        $this->compressOuter(
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
    public function compressDirZst(string $sourceDir, string $outputPath): void
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
        $this->compressOuter(
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
        // Reject symlinked source and archive paths before staging can follow them.
        SymlinkGuard::assertSymlinkFreePath($sourcePath, 'TAR source path');
        SymlinkGuard::assertSymlinkFreePath($archivePath, 'TAR archive path');

        // Source path must exist before staging/archive update.
        if (!file_exists($sourcePath)) {
            throw new RuntimeException('TAR source path could not be resolved: ' . $sourcePath);
        }

        $archiveDirectory = dirname($archivePath);
        // Ensure archive destination directory exists.
        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new RuntimeException('Failed to create TAR archive directory.');
        }

        $staging = $this->stagePath($sourcePath, $entryName);

        // Run tar add/update command from staging tree; clean up staging in finally.
        try {
            $command = [
                is_file($archivePath) ? '-rf' : '-cf',
                $archivePath,
                '-C',
                $staging['directory'],
                $staging['target'],
            ];
            $result = $this->runBinary($command);
            // Non-zero tar exit status is surfaced as a runtime exception.
            if (!$result['ok']) {
                throw new RuntimeException($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to update TAR archive: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->deleteTree($staging['directory']);
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
        SymlinkGuard::assertSymlinkFreePath($archivePath, 'TAR archive path');
        $symlinkEntries = $this->symlinkEntries($archivePath);

        // Wrap PharData read/list errors in a Raven-format runtime exception.
        try {
            return $this->withPharArchive($archivePath, function (string $pharArchivePath) use ($archivePath, $symlinkEntries): array {
                $phar = new PharData($pharArchivePath);
                $entries = [];
                $skippedLinkPrefixes = [];
                // Traverse archive entries and collect normalized entry paths.
                foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                    // Keep only PharFileInfo entries returned by the iterator.
                    if ($file instanceof \PharFileInfo) {
                        $entryPath = $this->pharEntryPath($file, $pharArchivePath);
                        $normalizedPath = trim(str_replace('\\', '/', $entryPath), '/');
                        if ($normalizedPath === '' || !$this->isSafeEntryPath($normalizedPath)) {
                            continue;
                        }

                        if (isset($symlinkEntries[$normalizedPath])) {
                            continue;
                        }

                        foreach ($skippedLinkPrefixes as $prefix) {
                            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                                continue 2;
                            }
                        }

                        if ($file->isLink()) {
                            if ($file->isDir()) {
                                $skippedLinkPrefixes[] = $normalizedPath;
                            }
                            continue;
                        }

                        // Skip entries that normalize to empty relative paths.
                        if ($entryPath !== '') {
                            // Preserve explicit directory markers with trailing slash.
                            if ($file->isDir()) {
                                $entryPath = rtrim($entryPath, '/') . '/';
                            }
                            $entries[] = $entryPath;
                        }
                    }
                }

                return $entries;
            });
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to list entries in TAR archive "' . basename($archivePath) . '": ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Returns symlink member names reported by the system TAR listing.
     *
     * PharData normalizes some TAR symlinks as regular file metadata, so the
     * system listing remains the authoritative member-type check for archives
     * created by Raven's TAR binary.
     *
     * @param string $archivePath Absolute path to the TAR archive.
     * @return array<string, bool> Relative symlink member names.
     */
    private function symlinkEntries(string $archivePath): array
    {
        $result = $this->runBinary(['-t', '-v', '-f', $archivePath]);
        if (!$result['ok']) {
            return [];
        }

        $entries = [];
        foreach (preg_split("/\r?\n/", $result['stdout']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'l')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 6);
            $entry = is_array($parts) && isset($parts[5]) ? trim((string) $parts[5]) : '';
            $targetSeparator = strpos($entry, ' -> ');
            if ($targetSeparator !== false) {
                $entry = substr($entry, 0, $targetSeparator);
            }

            $entry = trim(str_replace('\\', '/', $entry), '/');
            if ($entry !== '' && $this->isSafeEntryPath($entry)) {
                $entries[$entry] = true;
            }
        }

        return $entries;
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
        // Reject empty, absolute, or drive-prefixed entry paths.
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }

        // Reject null-byte paths.
        if (str_contains($path, "\0")) {
            return false;
        }

        // Reject dot-segment traversal path components.
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
    private function compressOuter(
        string $sourcePath,
        string $outputPath,
        callable $compressor,
        string $label
    ): void {
        $temporaryTarPath = $this->tempTar();

        // Build temporary tar then apply outer compression; clean up temp file in finally.
        try {
            // Source path must exist before staging/compression.
            SymlinkGuard::assertSymlinkFreePath($sourcePath, $label . ' source path');
            SymlinkGuard::assertSymlinkFreePath($outputPath, $label . ' output path');
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
    private function tempTar(): string
    {
        // Probe writable temp roots until tempnam/unlink yields a usable tar path.
        foreach ($this->tempRoots() as $directory) {
            $path = @tempnam($directory, 'rvn-tar-');
            // Skip unusable tempnam results.
            if (!is_string($path) || $path === '') {
                continue;
            }

            // PharData expects to create a fresh archive file itself; leaving the
            // tempnam placeholder in place causes it to interpret an empty file as
            // a truncated TAR. Remove the placeholder and return a fresh suffix path.
            // Skip candidate when placeholder cleanup fails.
            if (!@unlink($path)) {
                continue;
            }

            return $path . '.tar';
        }

        throw new RuntimeException('Failed to allocate temporary TAR path.');
    }

    /**
     * Returns writable directory roots Raven can use for staged TAR-family work.
     *
     * @return array<int, string> Writable temporary directory roots.
     */
    private function tempRoots(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $candidates = [
            $projectRoot . '/.tmp',
            $projectRoot . '/.tmp/archives',
        ];
        $directories = [];

        // Keep only writable temp roots, creating missing ones when possible.
        foreach ($candidates as $candidate) {
            // Skip empty candidate values.
            if ($candidate === '') {
                continue;
            }

            // TAR staging must not be redirected through a symlinked temp root.
            if (!SymlinkGuard::isSymlinkFreePath($candidate)) {
                continue;
            }

            // Attempt to create candidate directory when missing.
            if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
                continue;
            }

            // Skip non-writable candidates.
            if (!is_writable($candidate)) {
                continue;
            }

            $directories[] = rtrim($candidate, '/\\');
        }

        return array_values(array_unique($directories));
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
    private function pharEntryPath(\PharFileInfo $file, string $archivePath): string
    {
        $entryPath = str_replace('\\', '/', $file->getPathName());
        $candidatePrefixes = [
            'phar://' . str_replace('\\', '/', $archivePath) . '/',
        ];

        $resolvedArchivePath = realpath($archivePath);
        // Add realpath-based prefix variant when resolvable.
        if (is_string($resolvedArchivePath) && $resolvedArchivePath !== '') {
            $candidatePrefixes[] = 'phar://' . str_replace('\\', '/', $resolvedArchivePath) . '/';
        }

        // Strip the first matching phar wrapper prefix from the entry path.
        foreach ($candidatePrefixes as $prefix) {
            if (str_starts_with($entryPath, $prefix)) {
                return ltrim(substr($entryPath, strlen($prefix)), '/');
            }
        }

        return ltrim($file->getFilename(), '/');
    }

    /**
     * Runs one PharData operation against an archive path with a Phar-compatible suffix.
     *
     * PHP upload temporary files commonly have no filename suffix. PharData requires a
     * recognized archive extension even when the underlying bytes are a valid TAR, so a
     * suffixless upload is copied to a temporary `.tar` path for the duration of the read.
     *
     * @template T
     * @param string $archivePath Absolute source TAR path, possibly suffixless.
     * @param callable(string): T $operation Callback receiving the Phar-compatible path.
     * @return T Callback result.
     * @throws RuntimeException When the temporary TAR copy cannot be prepared.
     */
    private function withPharArchive(string $archivePath, callable $operation): mixed
    {
        $lowerPath = strtolower($archivePath);
        if (str_ends_with($lowerPath, '.tar') || str_ends_with($lowerPath, '.tar.gz')) {
            return $operation($archivePath);
        }

        $temporaryTarPath = $this->tempTar();
        try {
            if (!@copy($archivePath, $temporaryTarPath)) {
                throw new RuntimeException('Failed to prepare suffixless TAR upload for PharData.');
            }

            return $operation($temporaryTarPath);
        } finally {
            @unlink($temporaryTarPath);
        }
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
        // Reject unsafe normalized entry paths.
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
    private function normalizeDirEntry(string $entryName): string
    {
        return rtrim($this->normalizeEntryName($entryName), '/') . '/';
    }

    /**
     * Stages one source file or directory under the requested archive entry path.
     *
     * TAR creation uses the system `tar` binary, so Raven stages the source tree
     * first when it needs a custom archive root name. The staging copy preserves
     * mtimes and modes so the final tarball reflects the original source tree.
     *
     * @param string $sourcePath Absolute source file or directory path.
     * @param string|null $entryName Optional archive-internal destination path.
     * @return array{directory: string, target: string} Staging directory and tar command target.
     * @throws RuntimeException When the source cannot be staged.
     */
    private function stagePath(string $sourcePath, ?string $entryName): array
    {
        $stagingDirectory = $this->tempDir();
        $stagedEntryPath = $this->stagedPath($sourcePath, $entryName);
        $destinationPath = $stagingDirectory . '/' . $stagedEntryPath;
        $destinationDirectory = dirname($destinationPath);

        // Stage copy paths may be nested by entry name, so create the parent chain up front.
        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            $this->deleteTree($stagingDirectory);
            throw new RuntimeException('Failed to prepare TAR staging directory.');
        }

        // Any failed copy leaves temporary partial state, so always tear staging down on exceptions.
        try {
            // Directory sources preserve their tree shape; file sources map directly to one target path.
            if (is_dir($sourcePath)) {
                $this->copyTree($sourcePath, $destinationPath);
            } else {
                $this->copyFile($sourcePath, $destinationPath);
            }
        } catch (\Throwable $exception) {
            $this->deleteTree($stagingDirectory);
            throw $exception;
        }

        return [
            'directory' => $stagingDirectory,
            'target' => $this->stagedTarget($sourcePath, $entryName),
        ];
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
        // Explicit entry names take precedence so callers can control archive-internal paths.
        if (is_string($entryName) && trim($entryName) !== '') {
            return $this->normalizeEntryName($entryName);
        }

        return trim(basename($sourcePath), '/');
    }

    /**
     * Returns the top-level staging target the tar command should archive.
     *
     * Nested entry names are staged under their requested parent directories, so
     * the command only needs the first path segment to include the full tree.
     *
     * @param string $sourcePath Absolute file or directory path to stage.
     * @param string|null $entryName Optional archive-internal root path.
     * @return string Relative staging target for the tar command.
     */
    private function stagedTarget(string $sourcePath, ?string $entryName): string
    {
        $stagedEntryPath = $this->stagedPath($sourcePath, $entryName);
        $segments = explode('/', $stagedEntryPath);

        return $segments[0] !== '' ? $segments[0] : trim(basename($sourcePath), '/');
    }

    /**
     * Allocates one unique temporary directory for TAR staging work.
     *
     * @return string Absolute temporary directory path.
     * @throws RuntimeException When the directory cannot be created.
     */
    private function tempDir(): string
    {
        // Probe Raven-local temp roots so archive work stays out of daemon state.
        foreach ($this->tempRoots() as $directory) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $path = $directory . '/rvn-tar-stage-' . bin2hex(random_bytes(6));
                // Fresh directory creation is the normal success path.
                if (mkdir($path, 0775, true)) {
                    return $path;
                }

                // Concurrent creators can win the race; treat an existing directory as usable.
                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        throw new RuntimeException('Failed to allocate temporary TAR directory.');
    }

    /**
     * Copies one directory tree recursively while preserving mtimes and modes.
     *
     * Symlinks are skipped so archive exports do not escape the source tree or
     * encode environment-specific link targets into shipped packages.
     *
     * @param string $sourceDir Absolute source directory.
     * @param string $targetDir Absolute destination directory.
     * @return void
     * @throws RuntimeException When one file or directory cannot be copied.
     */
    private function copyTree(string $sourceDir, string $targetDir): void
    {
        // Source directory copies need a root destination before iterating entries.
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create TAR staging directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $directoryMetadata = [];

        // Walk each node once so directory metadata can be restored after file copies.
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $absolutePath = $item->getPathname();
            $relativePath = ltrim(substr($absolutePath, strlen($sourceDir)), DIRECTORY_SEPARATOR);
            // Ignore the synthetic root iterator item so we do not duplicate target roots.
            if ($relativePath === '') {
                continue;
            }

            $destinationPath = $targetDir . '/' . str_replace('\\', '/', $relativePath);
            // Skip linked child destinations while continuing other entries.
            if (!SymlinkGuard::isSymlinkFreePath($destinationPath)) {
                continue;
            }
            // Directories are created and tracked so their original mode/mtime can be restored later.
            if ($item->isDir()) {
                if (!is_dir($destinationPath) && !mkdir($destinationPath, 0775, true) && !is_dir($destinationPath)) {
                    throw new RuntimeException('Failed to create TAR staging directory path.');
                }

                $directoryMetadata[] = [
                    'path' => $destinationPath,
                    'mode' => $item->getPerms() & 0777,
                    'mtime' => $item->getMTime(),
                ];
                continue;
            }

            $this->copyFile($absolutePath, $destinationPath);
        }

        // Directories get their mtime bumped while files are copied into them,
        // so restore them from deepest path to root after staging completes.
        usort($directoryMetadata, static function (array $left, array $right): int {
            return substr_count((string) $right['path'], '/') <=> substr_count((string) $left['path'], '/');
        });

        // Restore permissions and mtimes after copy operations that touched directory metadata.
        foreach ($directoryMetadata as $directory) {
            @chmod((string) $directory['path'], (int) $directory['mode']);
            @touch((string) $directory['path'], (int) $directory['mtime']);
        }

        @chmod($targetDir, fileperms($sourceDir) & 0777);
        @touch($targetDir, filemtime($sourceDir) ?: time());
    }

    /**
     * Copies one file while preserving its mtime and filesystem mode.
     *
     * @param string $sourcePath Absolute source file path.
     * @param string $destinationPath Absolute destination file path.
     * @return void
     * @throws RuntimeException When the file cannot be copied.
     */
    private function copyFile(string $sourcePath, string $destinationPath): void
    {
        $destinationDirectory = dirname($destinationPath);
        // Extract targets may require nested folders, so ensure the parent chain exists first.
        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            throw new RuntimeException('Failed to create TAR staging parent directory.');
        }

        // Bubble copy failures so callers stop archive generation instead of emitting partial data.
        if (!@copy($sourcePath, $destinationPath)) {
            throw new RuntimeException('Failed to copy file into TAR staging directory.');
        }

        @chmod($destinationPath, fileperms($sourcePath) & 0777);
        @touch($destinationPath, filemtime($sourcePath) ?: time());
    }

    /**
     * Deletes one temporary directory tree recursively.
     *
     * @param string $directory Absolute directory path to delete.
     * @return void
     */
    private function deleteTree(string $directory): void
    {
        // Remove a symlink entry itself without traversing its target.
        if (is_link($directory)) {
            @unlink($directory);
            return;
        }

        // Empty or missing roots indicate cleanup already happened, so nothing needs deleting.
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        // Delete children before parents so directory removal succeeds consistently.
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            // Remove linked children without following them during staging cleanup.
            if ($item->isLink()) {
                @unlink($path);
                continue;
            }

            if ($item->isDir()) {
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * Runs the tar binary without shell interpolation.
     *
     * @param array<int, string> $arguments Binary arguments excluding the executable.
     * @param string|null $workingDirectory Optional working directory.
     * @return array{ok: bool, stdout: string, stderr: string, exit_code: int}
     */
    private function runBinary(array $arguments, ?string $workingDirectory = null): array
    {
        $command = array_merge([$this->binary], $arguments);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $workingDirectory);
        // Process startup failures still return a normalized error shape for all callers.
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'stdout' => '',
                'stderr' => 'Failed to start tar process.',
                'exit_code' => 1,
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'stdout' => is_string($stdout) ? trim($stdout) : '',
            'stderr' => is_string($stderr) ? trim($stderr) : '',
            'exit_code' => is_int($exitCode) ? $exitCode : 1,
        ];
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
        SymlinkGuard::assertSymlinkFreePath($targetPath, 'TAR extraction path');

        $directory = dirname($targetPath);
        // Individual extracted files can be nested, so create missing parents before writing.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create directory for extracted TAR entry.');
        }

        // Fail fast when disk writes do not complete so extraction callers can abort safely.
        if (file_put_contents($targetPath, $contents) === false) {
            throw new RuntimeException('Failed to write extracted TAR entry to "' . $targetPath . '".');
        }
    }
}
