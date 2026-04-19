<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Extract.php
 * Archive extraction forwarder for Raven's supported container and single-file formats.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Format\Bz2;
use Raven\Lib\Format\Gz;
use Raven\Lib\Format\Rar;
use Raven\Lib\Format\SevenZip;
use Raven\Lib\Format\Tar;
use Raven\Lib\Format\Xz;
use Raven\Lib\Format\Zip;
use Raven\Lib\Format\Zst;
use RuntimeException;

/**
 * Routes archive extraction requests to the canonical format handlers.
 *
 * This service centralizes archive-format detection so Raven can expose one
 * stable extraction surface for package uploads, archive tooling, and selective
 * file/folder reads without duplicating outer-layer decompression rules.
 */
final class Extract
{
    private Zip $zip;
    private Tar $tar;
    private Rar $rar;
    private SevenZip $sevenZip;
    private Gz $gz;
    private Bz2 $bz2;
    private Xz $xz;
    private Zst $zst;

    /**
     * @param Zip|null $zip ZIP archive handler override for tests/composition.
     * @param Tar|null $tar TAR archive handler override for tests/composition.
     * @param Rar|null $rar RAR archive handler override for tests/composition.
     * @param SevenZip|null $sevenZip 7-Zip archive handler override for tests/composition.
     * @param Gz|null $gz Gzip decompression handler override for tests/composition.
     * @param Bz2|null $bz2 Bzip2 decompression handler override for tests/composition.
     * @param Xz|null $xz XZ decompression handler override for tests/composition.
     * @param Zst|null $zst Zstandard decompression handler override for tests/composition.
     */
    public function __construct(
        ?Zip $zip = null,
        ?Tar $tar = null,
        ?Rar $rar = null,
        ?SevenZip $sevenZip = null,
        ?Gz $gz = null,
        ?Bz2 $bz2 = null,
        ?Xz $xz = null,
        ?Zst $zst = null
    ) {
        $this->zip = $zip ?? new Zip();
        $this->tar = $tar ?? new Tar();
        $this->rar = $rar ?? new Rar();
        $this->sevenZip = $sevenZip ?? new SevenZip();
        $this->gz = $gz ?? new Gz();
        $this->bz2 = $bz2 ?? new Bz2();
        $this->xz = $xz ?? new Xz();
        $this->zst = $zst ?? new Zst();
    }

    /**
     * Returns supported package-archive extensions for install/import workflows.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function supportedPackageExtensions(): array
    {
        return [
            'zip',
            'tar',
            'tar.gz',
            'tgz',
            'tar.bz2',
            'tbz2',
            'tar.xz',
            'txz',
            'tar.zst',
            'tzst',
            '7z',
            'rar',
        ];
    }

    /**
     * Returns an HTML file-input `accept` value for supported package archives.
     *
     * Compound archive types still require server-side validation because most
     * browsers can only loosely filter by the outermost suffix.
     *
     * @return string Comma-delimited `accept` attribute value.
     */
    public function packageArchiveAcceptAttribute(): string
    {
        return implode(',', [
            '.zip',
            '.tar',
            '.tgz',
            '.gz',
            '.tbz2',
            '.bz2',
            '.txz',
            '.xz',
            '.tzst',
            '.zst',
            '.7z',
            '.rar',
            'application/zip',
            'application/x-tar',
            'application/gzip',
            'application/x-gzip',
            'application/x-bzip2',
            'application/x-7z-compressed',
            'application/x-rar-compressed',
        ]);
    }

    /**
     * Returns true when the filename uses a supported package-archive suffix.
     *
     * @param string $filename Uploaded/archive filename to inspect.
     * @return bool True when Raven can extract the package.
     */
    public function isSupportedPackageArchiveName(string $filename): bool
    {
        return $this->detectPackageArchiveType($filename) !== null;
    }

    /**
     * Extracts a supported archive package into a target directory.
     *
     * TAR-family compressed formats are first decompressed to a temporary `.tar`
     * via their dedicated single-file handlers, then extracted through `Tar`.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $targetDir Absolute path to the destination directory.
     * @return void
     * @throws RuntimeException When the archive type is unsupported or extraction fails.
     */
    public function extractTo(string $archivePath, string $targetDir): void
    {
        $type = $this->requirePackageArchiveType($archivePath);

        match ($type) {
            'zip' => $this->zip->extractTo($archivePath, $targetDir),
            'tar' => $this->tar->extractTo($archivePath, $targetDir),
            'tar.gz' => $this->withTemporaryTarFromCompressed($archivePath, 'gz', static function (string $tarPath) use ($targetDir): void {
                (new Tar())->extractTo($tarPath, $targetDir);
            }),
            'tar.bz2' => $this->withTemporaryTarFromCompressed($archivePath, 'bz2', static function (string $tarPath) use ($targetDir): void {
                (new Tar())->extractTo($tarPath, $targetDir);
            }),
            'tar.xz' => $this->withTemporaryTarFromCompressed($archivePath, 'xz', static function (string $tarPath) use ($targetDir): void {
                (new Tar())->extractTo($tarPath, $targetDir);
            }),
            'tar.zst' => $this->withTemporaryTarFromCompressed($archivePath, 'zst', static function (string $tarPath) use ($targetDir): void {
                (new Tar())->extractTo($tarPath, $targetDir);
            }),
            '7z' => $this->sevenZip->extractTo($archivePath, $targetDir),
            'rar' => $this->rar->extractTo($archivePath, $targetDir),
            default => throw new RuntimeException('Unsupported archive type: ' . $archivePath),
        };
    }

    /**
     * Extracts one full archive payload to a target path across all supported formats.
     *
     * Container archives extract into a directory target. Single-file formats
     * (`.gz`, `.bz2`, `.xz`, `.zst`) decompress to one file path; when the
     * provided target resolves to a directory, Raven derives the output filename
     * from the archive suffix automatically.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $targetPath Absolute output directory or file path.
     * @return void
     * @throws RuntimeException When the archive type is unsupported or extraction fails.
     */
    public function extractArchive(string $archivePath, string $targetPath): void
    {
        $type = $this->requireArchiveType($archivePath);

        match ($type) {
            'zip', 'tar', 'tar.gz', 'tar.bz2', 'tar.xz', 'tar.zst', '7z', 'rar' => $this->extractTo($archivePath, $targetPath),
            'gz' => $this->gz->decompress($archivePath, $this->resolveSingleFileTargetPath($archivePath, $targetPath, '.gz')),
            'bz2' => $this->bz2->decompress($archivePath, $this->resolveSingleFileTargetPath($archivePath, $targetPath, '.bz2')),
            'xz' => $this->xz->decompress($archivePath, $this->resolveSingleFileTargetPath($archivePath, $targetPath, '.xz')),
            'zst' => $this->zst->decompress($archivePath, $this->resolveSingleFileTargetPath($archivePath, $targetPath, '.zst')),
            default => throw new RuntimeException('Unsupported archive type: ' . $archivePath),
        };
    }

    /**
     * Extracts one named archive entry to a target file path.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $entryName Archive-internal path to extract.
     * @param string $targetPath Absolute output path for the extracted file.
     * @return void
     * @throws RuntimeException When the archive type is unsupported or extraction fails.
     */
    public function extractFile(string $archivePath, string $entryName, string $targetPath): void
    {
        $type = $this->requirePackageArchiveType($archivePath);

        match ($type) {
            'zip' => $this->zip->extractFile($archivePath, $entryName, $targetPath),
            'tar' => $this->tar->extractFile($archivePath, $entryName, $targetPath),
            'tar.gz' => $this->withTemporaryTarFromCompressed($archivePath, 'gz', function (string $tarPath) use ($entryName, $targetPath): void {
                $this->tar->extractFile($tarPath, $entryName, $targetPath);
            }),
            'tar.bz2' => $this->withTemporaryTarFromCompressed($archivePath, 'bz2', function (string $tarPath) use ($entryName, $targetPath): void {
                $this->tar->extractFile($tarPath, $entryName, $targetPath);
            }),
            'tar.xz' => $this->withTemporaryTarFromCompressed($archivePath, 'xz', function (string $tarPath) use ($entryName, $targetPath): void {
                $this->tar->extractFile($tarPath, $entryName, $targetPath);
            }),
            'tar.zst' => $this->withTemporaryTarFromCompressed($archivePath, 'zst', function (string $tarPath) use ($entryName, $targetPath): void {
                $this->tar->extractFile($tarPath, $entryName, $targetPath);
            }),
            '7z' => $this->sevenZip->extractFile($archivePath, $entryName, $targetPath),
            'rar' => $this->rar->extractFile($archivePath, $entryName, $targetPath),
            default => throw new RuntimeException('Unsupported archive type: ' . $archivePath),
        };
    }

    /**
     * Extracts one named archive directory into a target directory.
     *
     * Item-level directory extraction only applies to archive formats that
     * actually store named entries as a directory tree: `7z`, `rar`, `tar`,
     * and `zip` (including compressed TAR variants via temporary outer-layer
     * decompression).
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $entryName Archive-internal directory path to extract.
     * @param string $targetDir Absolute output directory for the extracted contents.
     * @return void
     * @throws RuntimeException When the archive type does not support directory extraction.
     */
    public function extractDirectory(string $archivePath, string $entryName, string $targetDir): void
    {
        $type = $this->requirePackageArchiveType($archivePath);

        match ($type) {
            'zip' => $this->zip->extractDirectory($archivePath, $entryName, $targetDir),
            'tar' => $this->tar->extractDirectory($archivePath, $entryName, $targetDir),
            'tar.gz' => $this->withTemporaryTarFromCompressed($archivePath, 'gz', function (string $tarPath) use ($entryName, $targetDir): void {
                $this->tar->extractDirectory($tarPath, $entryName, $targetDir);
            }),
            'tar.bz2' => $this->withTemporaryTarFromCompressed($archivePath, 'bz2', function (string $tarPath) use ($entryName, $targetDir): void {
                $this->tar->extractDirectory($tarPath, $entryName, $targetDir);
            }),
            'tar.xz' => $this->withTemporaryTarFromCompressed($archivePath, 'xz', function (string $tarPath) use ($entryName, $targetDir): void {
                $this->tar->extractDirectory($tarPath, $entryName, $targetDir);
            }),
            'tar.zst' => $this->withTemporaryTarFromCompressed($archivePath, 'zst', function (string $tarPath) use ($entryName, $targetDir): void {
                $this->tar->extractDirectory($tarPath, $entryName, $targetDir);
            }),
            '7z' => $this->sevenZip->extractDirectory($archivePath, $entryName, $targetDir),
            'rar' => $this->rar->extractDirectory($archivePath, $entryName, $targetDir),
            default => throw new RuntimeException('Unsupported archive type for directory extraction: ' . $archivePath),
        };
    }

    /**
     * Returns all entry names in a supported package archive.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @return array<int, string> Normalized archive-internal entry names.
     * @throws RuntimeException When the archive type is unsupported or inspection fails.
     */
    public function listEntries(string $archivePath): array
    {
        $type = $this->requirePackageArchiveType($archivePath);

        return match ($type) {
            'zip' => $this->zip->listEntries($archivePath),
            'tar' => $this->tar->listEntries($archivePath),
            'tar.gz' => $this->withTemporaryTarFromCompressed($archivePath, 'gz', function (string $tarPath): array {
                return $this->tar->listEntries($tarPath);
            }),
            'tar.bz2' => $this->withTemporaryTarFromCompressed($archivePath, 'bz2', function (string $tarPath): array {
                return $this->tar->listEntries($tarPath);
            }),
            'tar.xz' => $this->withTemporaryTarFromCompressed($archivePath, 'xz', function (string $tarPath): array {
                return $this->tar->listEntries($tarPath);
            }),
            'tar.zst' => $this->withTemporaryTarFromCompressed($archivePath, 'zst', function (string $tarPath): array {
                return $this->tar->listEntries($tarPath);
            }),
            '7z' => $this->sevenZip->listEntries($archivePath),
            'rar' => $this->rar->listEntries($archivePath),
            default => throw new RuntimeException('Unsupported archive type: ' . $archivePath),
        };
    }

    /**
     * Reads a slug value from an archive manifest at root or one wrapper level deep.
     *
     * ZIP archives delegate directly to `Zip::slugFromManifest()`. Other archive
     * types inspect entry names, extract candidate manifest files to temporary
     * JSON files, and then validate the `slug` field with the same regex policy.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $manifestFilename Manifest basename to search for.
     * @param int $maxSlugLength Maximum slug length allowed by the caller.
     * @return string|null Valid slug string, or null when no candidate manifest matches.
     */
    public function slugFromManifest(string $archivePath, string $manifestFilename, int $maxSlugLength): ?string
    {
        $type = $this->requirePackageArchiveType($archivePath);
        if ($type === 'zip') {
            return $this->zip->slugFromManifest($archivePath, $manifestFilename, $maxSlugLength);
        }

        $manifestFile = strtolower(trim($manifestFilename));
        if ($manifestFile === '') {
            return null;
        }

        $slugPattern = '/^[a-z0-9][a-z0-9_-]{0,' . max(0, $maxSlugLength) . '}$/';

        foreach ($this->manifestCandidateEntries($archivePath, $manifestFile) as $entryName) {
            $tmpManifestPath = $this->temporaryFilePath('.json');

            try {
                $this->extractFile($archivePath, $entryName, $tmpManifestPath);

                $raw = @file_get_contents($tmpManifestPath);
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
            } finally {
                @unlink($tmpManifestPath);
            }
        }

        return null;
    }

    /**
     * Returns candidate manifest entry paths in preferred depth order.
     *
     * Root-level manifest files are returned before one-wrapper-directory files
     * so callers can prefer the archive root when both layouts exist.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $manifestFilename Lowercase manifest basename to search for.
     * @return array<int, string> Candidate manifest entry paths.
     */
    private function manifestCandidateEntries(string $archivePath, string $manifestFilename): array
    {
        $rootEntries = [];
        $wrappedEntries = [];

        foreach ($this->listEntries($archivePath) as $entryName) {
            $normalized = trim(str_replace('\\', '/', $entryName), '/');
            if ($normalized === '' || strtolower((string) pathinfo($normalized, PATHINFO_BASENAME)) !== $manifestFilename) {
                continue;
            }

            $directory = trim((string) pathinfo($normalized, PATHINFO_DIRNAME), '.');
            $depth = $directory === '' ? 0 : substr_count($directory, '/') + 1;
            if ($depth > 1) {
                continue;
            }

            if ($depth === 0) {
                $rootEntries[] = $normalized;
            } else {
                $wrappedEntries[] = $normalized;
            }
        }

        return [...$rootEntries, ...$wrappedEntries];
    }

    /**
     * Decompresses a compressed TAR outer layer to a temporary `.tar`, then runs a callback.
     *
     * The temporary tarball is always removed, even when the handler throws.
     *
     * @template T
     * @param string $archivePath Absolute path to the compressed TAR archive.
     * @param string $compressionType Compression type key: `gz`, `bz2`, `xz`, or `zst`.
     * @param callable(string): T $operation Callback that receives the temporary `.tar` path.
     * @return T Callback result.
     */
    private function withTemporaryTarFromCompressed(string $archivePath, string $compressionType, callable $operation): mixed
    {
        $temporaryTarPath = $this->temporaryFilePath('.tar');

        try {
            match ($compressionType) {
                'gz' => $this->gz->decompress($archivePath, $temporaryTarPath),
                'bz2' => $this->bz2->decompress($archivePath, $temporaryTarPath),
                'xz' => $this->xz->decompress($archivePath, $temporaryTarPath),
                'zst' => $this->zst->decompress($archivePath, $temporaryTarPath),
                default => throw new RuntimeException('Unsupported compressed TAR type: ' . $compressionType),
            };

            return $operation($temporaryTarPath);
        } finally {
            @unlink($temporaryTarPath);
        }
    }

    /**
     * Detects and returns one supported package-archive type from a filename/path.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string|null Canonical archive type key, or null when unsupported.
     */
    private function detectPackageArchiveType(string $archivePath): ?string
    {
        $filename = strtolower(trim((string) pathinfo($archivePath, PATHINFO_BASENAME)));
        if ($filename === '') {
            return null;
        }

        foreach ([
            '.tar.gz' => 'tar.gz',
            '.tgz' => 'tar.gz',
            '.tar.bz2' => 'tar.bz2',
            '.tbz2' => 'tar.bz2',
            '.tar.xz' => 'tar.xz',
            '.txz' => 'tar.xz',
            '.tar.zst' => 'tar.zst',
            '.tzst' => 'tar.zst',
            '.7z' => '7z',
            '.zip' => 'zip',
            '.tar' => 'tar',
            '.rar' => 'rar',
        ] as $suffix => $type) {
            if (str_ends_with($filename, $suffix)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Returns one supported package-archive type or throws for unsupported names.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string Canonical archive type key.
     * @throws RuntimeException When the filename/path does not use a supported suffix.
     */
    private function requirePackageArchiveType(string $archivePath): string
    {
        $type = $this->detectPackageArchiveType($archivePath);
        if ($type === null) {
            throw new RuntimeException('Unsupported archive type: ' . $archivePath);
        }

        return $type;
    }

    /**
     * Detects and returns one supported archive type from a filename/path.
     *
     * Package/container formats and single-file compression formats both map to
     * canonical type keys so `extractArchive()` can route everything through one
     * stable detection layer.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string|null Canonical archive type key, or null when unsupported.
     */
    private function detectArchiveType(string $archivePath): ?string
    {
        $packageType = $this->detectPackageArchiveType($archivePath);
        if ($packageType !== null) {
            return $packageType;
        }

        $filename = strtolower(trim((string) pathinfo($archivePath, PATHINFO_BASENAME)));
        if ($filename === '') {
            return null;
        }

        foreach ([
            '.gz' => 'gz',
            '.bz2' => 'bz2',
            '.xz' => 'xz',
            '.zst' => 'zst',
        ] as $suffix => $type) {
            if (str_ends_with($filename, $suffix)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Returns one supported archive type or throws for unsupported names.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string Canonical archive type key.
     * @throws RuntimeException When the filename/path does not use a supported suffix.
     */
    private function requireArchiveType(string $archivePath): string
    {
        $type = $this->detectArchiveType($archivePath);
        if ($type === null) {
            throw new RuntimeException('Unsupported archive type: ' . $archivePath);
        }

        return $type;
    }

    /**
     * Allocates one temporary file path, optionally with a stable suffix.
     *
     * @param string $suffix Optional suffix such as `.tar` or `.json`.
     * @return string Absolute temporary file path reserved for this process.
     * @throws RuntimeException When no temporary file path can be created.
     */
    private function temporaryFilePath(string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rvn-archive-');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Failed to allocate temporary archive path.');
        }

        if ($suffix === '') {
            return $path;
        }

        $suffixedPath = $path . $suffix;
        if (!@rename($path, $suffixedPath)) {
            @unlink($path);
            throw new RuntimeException('Failed to prepare temporary archive path.');
        }

        return $suffixedPath;
    }

    /**
     * Resolves the final output path for a single-file archive extraction.
     *
     * Existing directories, or targets that explicitly end with a directory
     * separator, receive one derived filename based on the archive suffix.
     *
     * @param string $archivePath Absolute source archive path.
     * @param string $targetPath Requested output path or directory.
     * @param string $suffix Canonical archive suffix including the leading dot.
     * @return string Absolute output file path.
     * @throws RuntimeException When the output directory cannot be prepared.
     */
    private function resolveSingleFileTargetPath(string $archivePath, string $targetPath, string $suffix): string
    {
        $treatAsDirectory = is_dir($targetPath) || preg_match('#[\\\\/]$#', $targetPath) === 1;
        if (!$treatAsDirectory) {
            return $targetPath;
        }

        $directory = rtrim($targetPath, '/\\');
        if ($directory === '') {
            $directory = '.';
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create single-file extraction directory: ' . $directory);
        }

        return $directory . '/' . $this->derivedSingleFileName($archivePath, $suffix);
    }

    /**
     * Derives the decompressed filename for one single-file archive.
     *
     * @param string $archivePath Absolute source archive path.
     * @param string $suffix Canonical archive suffix including the leading dot.
     * @return string Derived output basename.
     */
    private function derivedSingleFileName(string $archivePath, string $suffix): string
    {
        $basename = (string) pathinfo($archivePath, PATHINFO_BASENAME);
        if (str_ends_with(strtolower($basename), strtolower($suffix))) {
            return substr($basename, 0, -strlen($suffix));
        }

        return $basename . '.out';
    }
}
