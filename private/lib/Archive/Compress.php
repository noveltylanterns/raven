<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Compress.php
 * Archive compression forwarder for Raven's supported container and single-file formats.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Format\Bz2;
use Raven\Lib\Format\Gz;
use Raven\Lib\Format\Szip;
use Raven\Lib\Format\Tar;
use Raven\Lib\Format\Xz;
use Raven\Lib\Format\Zip;
use Raven\Lib\Format\Zst;
use RuntimeException;

/**
 * Routes archive-building requests to the canonical format handlers.
 *
 * This facade keeps package/export code out of format-specific branching while
 * still letting Raven expose one stable compression surface for full-archive
 * creation, single-file compression, and selective file/folder updates.
 */
final class Compress
{
    private Zip $zip;
    private Tar $tar;
    private Szip $szip;
    private Gz $gz;
    private Bz2 $bz2;
    private Xz $xz;
    private Zst $zst;

    /**
     * @param Zip|null $zip ZIP archive handler override for tests/composition.
     * @param Tar|null $tar TAR archive handler override for tests/composition.
     * @param Szip|null $szip 7-Zip archive handler override for tests/composition.
     * @param Gz|null $gz Gzip compression handler override for tests/composition.
     * @param Bz2|null $bz2 Bzip2 compression handler override for tests/composition.
     * @param Xz|null $xz XZ compression handler override for tests/composition.
     * @param Zst|null $zst Zstandard compression handler override for tests/composition.
     */
    public function __construct(
        ?Zip $zip = null,
        ?Tar $tar = null,
        ?Szip $szip = null,
        ?Gz $gz = null,
        ?Bz2 $bz2 = null,
        ?Xz $xz = null,
        ?Zst $zst = null
    )
    {
        $this->zip = $zip ?? new Zip();
        $this->tar = $tar ?? new Tar();
        $this->szip = $szip ?? new Szip();
        $this->gz = $gz ?? new Gz();
        $this->bz2 = $bz2 ?? new Bz2();
        $this->xz = $xz ?? new Xz();
        $this->zst = $zst ?? new Zst();
    }

    /**
     * Returns supported multi-file archive extensions for directory compression.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function dirFormats(): array
    {
        return [
            'zip',
            '7z',
            'tar',
            'tar.gz',
            'tar.bz2',
            'tar.xz',
            'tar.zst',
        ];
    }

    /**
     * Returns supported single-file compression extensions.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function fileFormats(): array
    {
        return [
            'gz',
            'bz2',
            'xz',
            'zst',
        ];
    }

    /**
     * Returns every archive extension supported by the shared compression surface.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function formats(): array
    {
        return array_values(array_unique([
            ...$this->dirFormats(),
            ...$this->fileFormats(),
        ]));
    }

    /**
     * Compresses a source file or directory into one archive file chosen by suffix.
     *
     * Container formats (`zip`, `7z`, `tar*`) can wrap one source path
     * under `$archiveRoot`. Single-file formats (`gz`, `bz2`, `xz`, `zst`)
     * require a file source and ignore `$archiveRoot`.
     *
     * @param string $sourcePath Absolute path to the source file or directory.
     * @param string $archiveRoot Preferred archive-internal root name where supported.
     * @param string $outputPath Absolute output archive path.
     * @return void
     * @throws RuntimeException When the output suffix is unsupported or archiving fails.
     */
    public function compressPath(string $sourcePath, string $archiveRoot, string $outputPath): void
    {
        $type = $this->type($outputPath);

        match ($type) {
            'zip' => $this->zip->compressPath($sourcePath, $this->containerEntry($sourcePath, $archiveRoot), $outputPath),
            '7z' => $this->szip->compressPath($sourcePath, $outputPath, $this->containerEntry($sourcePath, $archiveRoot)),
            'tar' => $this->tar->compressPath($sourcePath, $outputPath, $this->tarEntry($sourcePath, $archiveRoot)),
            'tar.gz' => $this->tar->compressPathGz($sourcePath, $outputPath, $this->tarEntry($sourcePath, $archiveRoot)),
            'tar.bz2' => $this->tar->compressPathBz2($sourcePath, $outputPath, $this->tarEntry($sourcePath, $archiveRoot)),
            'tar.xz' => $this->tar->compressPathXz($sourcePath, $outputPath, $this->tarEntry($sourcePath, $archiveRoot)),
            'tar.zst' => $this->tar->compressPathZst($sourcePath, $outputPath, $this->tarEntry($sourcePath, $archiveRoot)),
            'gz' => $this->gz->compress($this->fileSource($sourcePath, $type), $outputPath),
            'bz2' => $this->bz2->compress($this->fileSource($sourcePath, $type), $outputPath),
            'xz' => $this->xz->compress($this->fileSource($sourcePath, $type), $outputPath),
            'zst' => $this->zst->compress($this->fileSource($sourcePath, $type), $outputPath),
            default => throw new RuntimeException('Unsupported archive type: ' . $outputPath),
        };
    }

    /**
     * Compresses a source directory into one archive file chosen by output suffix.
     *
     * This directory-focused wrapper remains the package/export entrypoint used
     * by existing callers, while `compressPath()` now covers file sources too.
     *
     * @param string $sourceDir Absolute path to the source directory.
     * @param string $archiveRoot Preferred archive root wrapper name where supported.
     * @param string $outputPath Absolute output archive path.
     * @return void
     * @throws RuntimeException When the source is not a directory or archiving fails.
     */
    public function compressDir(string $sourceDir, string $archiveRoot, string $outputPath): void
    {
        // Directory-only wrapper rejects non-directory sources before delegating.
        if (!is_dir($sourceDir)) {
            throw new RuntimeException('Directory archive compression requires a directory source: ' . $sourceDir);
        }

        $this->compressPath($sourceDir, $archiveRoot, $outputPath);
    }

    /**
     * Returns true when the output filename uses a supported directory-archive suffix.
     *
     * @param string $filename Output filename or archive path to inspect.
     * @return bool True when `compressDir()` can handle the requested format.
     */
    public function supportsDir(string $filename): bool
    {
        return $this->detectDirType($filename) !== null;
    }

    /**
     * Returns true when the output filename uses any supported archive suffix.
     *
     * @param string $filename Output filename or archive path to inspect.
     * @return bool True when `compressPath()` can handle the requested format.
     */
    public function supports(string $filename): bool
    {
        return $this->detectType($filename) !== null;
    }

    /**
     * Adds or replaces one file or directory inside an existing archive.
     *
     * Selective path updates are supported on container formats that expose
     * stable entry trees: `7z`, `tar`, and `zip` (including compressed
     * TAR variants via a temporary outer-layer round-trip).
     *
     * @param string $archivePath Absolute path to the archive being modified.
     * @param string $sourcePath Absolute path to the file or directory to add.
     * @param string $entryName Archive-internal destination path.
     * @return void
     * @throws RuntimeException When the archive type cannot support path updates.
     */
    public function addPath(string $archivePath, string $sourcePath, string $entryName): void
    {
        $type = $this->dirType($archivePath);

        match ($type) {
            'zip' => $this->zip->addPath($archivePath, $sourcePath, $entryName),
            '7z' => $this->szip->addPath($archivePath, $sourcePath, $entryName),
            'tar' => $this->tar->addPath($archivePath, $sourcePath, $entryName),
            'tar.gz' => $this->withTempTar($archivePath, 'gz', function (string $tarPath) use ($sourcePath, $entryName): void {
                $this->tar->addPath($tarPath, $sourcePath, $entryName);
            }),
            'tar.bz2' => $this->withTempTar($archivePath, 'bz2', function (string $tarPath) use ($sourcePath, $entryName): void {
                $this->tar->addPath($tarPath, $sourcePath, $entryName);
            }),
            'tar.xz' => $this->withTempTar($archivePath, 'xz', function (string $tarPath) use ($sourcePath, $entryName): void {
                $this->tar->addPath($tarPath, $sourcePath, $entryName);
            }),
            'tar.zst' => $this->withTempTar($archivePath, 'zst', function (string $tarPath) use ($sourcePath, $entryName): void {
                $this->tar->addPath($tarPath, $sourcePath, $entryName);
            }),
            default => throw new RuntimeException('Unsupported archive type for path updates: ' . $archivePath),
        };
    }

    /**
     * Detects and returns one supported directory-archive type from a filename/path.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string|null Canonical archive type key, or null when unsupported.
     */
    private function detectDirType(string $archivePath): ?string
    {
        $filename = strtolower(trim((string) pathinfo($archivePath, PATHINFO_BASENAME)));
        // Empty basenames cannot be matched against archive suffixes.
        if ($filename === '') {
            return null;
        }

        // Match the longest known suffix map first to resolve canonical type keys.
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
        ] as $suffix => $type) {
            // Return on first matching suffix.
            if (str_ends_with($filename, $suffix)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Detects and returns one supported archive type from a filename/path.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string|null Canonical archive type key, or null when unsupported.
     */
    private function detectType(string $archivePath): ?string
    {
        $directoryType = $this->detectDirType($archivePath);
        // Directory-capable types are also valid generic archive types.
        if ($directoryType !== null) {
            return $directoryType;
        }

        $filename = strtolower(trim((string) pathinfo($archivePath, PATHINFO_BASENAME)));
        // Empty basenames cannot be matched against archive suffixes.
        if ($filename === '') {
            return null;
        }

        // Fall back to single-file compression suffix detection.
        foreach ([
            '.gz' => 'gz',
            '.bz2' => 'bz2',
            '.xz' => 'xz',
            '.zst' => 'zst',
        ] as $suffix => $type) {
            // Return on first matching suffix.
            if (str_ends_with($filename, $suffix)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Returns one supported directory-archive type or throws for unsupported names.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string Canonical archive type key.
     * @throws RuntimeException When the filename/path does not use a supported suffix.
     */
    private function dirType(string $archivePath): string
    {
        $type = $this->detectDirType($archivePath);
        // Unsupported suffixes fail fast with a consistent archive-type error.
        if ($type === null) {
            throw new RuntimeException('Unsupported archive type: ' . $archivePath);
        }

        return $type;
    }

    /**
     * Returns one supported archive type or throws for unsupported names.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string Canonical archive type key.
     * @throws RuntimeException When the filename/path does not use a supported suffix.
     */
    private function type(string $archivePath): string
    {
        $type = $this->detectType($archivePath);
        // Unsupported suffixes fail fast with a consistent archive-type error.
        if ($type === null) {
            throw new RuntimeException('Unsupported archive type: ' . $archivePath);
        }

        return $type;
    }

    /**
     * Returns the preferred archive entry name for container formats.
     *
     * @param string $sourcePath Absolute source path.
     * @param string $archiveRoot Preferred archive root name from the caller.
     * @return string Archive-internal root name.
     */
    private function containerEntry(string $sourcePath, string $archiveRoot): string
    {
        $root = trim(str_replace('\\', '/', $archiveRoot), '/');
        // Explicit archive roots take precedence when provided by callers.
        if ($root !== '') {
            return $root;
        }

        return trim(basename($sourcePath), '/');
    }

    /**
     * Returns the preferred archive entry name for TAR-family formats.
     *
     * Directory TAR archives preserve contents directly by default, so Raven
     * only wraps them when the caller explicitly requests one archive root.
     *
     * @param string $sourcePath Absolute source path.
     * @param string $archiveRoot Preferred archive root name from the caller.
     * @return string|null Archive-internal root name, or null for bare directory contents.
     */
    private function tarEntry(string $sourcePath, string $archiveRoot): ?string
    {
        $root = trim(str_replace('\\', '/', $archiveRoot), '/');
        // Explicit archive roots take precedence when provided by callers.
        if ($root !== '') {
            return $root;
        }

        // Directory TAR exports default to bare contents without an extra wrapper folder.
        if (is_dir($sourcePath)) {
            return null;
        }

        return trim(basename($sourcePath), '/');
    }

    /**
     * Ensures one source path is a file for single-file compression formats.
     *
     * @param string $sourcePath Absolute source path to validate.
     * @param string $type Human-readable archive type key for errors.
     * @return string Absolute validated file path.
     * @throws RuntimeException When the source is not a regular file.
     */
    private function fileSource(string $sourcePath, string $type): string
    {
        // File-only compression formats reject directory inputs.
        if (!is_file($sourcePath)) {
            throw new RuntimeException(strtoupper($type) . ' compression requires a file source: ' . $sourcePath);
        }

        return $sourcePath;
    }

    /**
     * Opens a compressed TAR archive for in-place mutation through a temporary `.tar`.
     *
     * The archive is decompressed to a temporary tarball when it already exists,
     * mutated by the caller, then recompressed back to the original output path.
     *
     * @template T
     * @param string $archivePath Absolute path to the compressed TAR archive.
     * @param string $compressionType Compression key: `gz`, `bz2`, `xz`, or `zst`.
     * @param callable(string): T $operation Callback that mutates the temporary `.tar`.
     * @return T Callback result.
     * @throws RuntimeException When decompression or recompression fails.
     */
    private function withTempTar(string $archivePath, string $compressionType, callable $operation): mixed
    {
        $temporaryTarPath = $this->tempPath('.tar');

        // Always clean temporary tar state even when mutation/compression fails.
        try {
            // Existing compressed TAR archives must be decompressed before mutation.
            if (is_file($archivePath)) {
                match ($compressionType) {
                    'gz' => $this->gz->decompress($archivePath, $temporaryTarPath),
                    'bz2' => $this->bz2->decompress($archivePath, $temporaryTarPath),
                    'xz' => $this->xz->decompress($archivePath, $temporaryTarPath),
                    'zst' => $this->zst->decompress($archivePath, $temporaryTarPath),
                    default => throw new RuntimeException('Unsupported compressed TAR type: ' . $compressionType),
                };
            }

            $result = $operation($temporaryTarPath);

            match ($compressionType) {
                'gz' => $this->gz->compress($temporaryTarPath, $archivePath),
                'bz2' => $this->bz2->compress($temporaryTarPath, $archivePath),
                'xz' => $this->xz->compress($temporaryTarPath, $archivePath),
                'zst' => $this->zst->compress($temporaryTarPath, $archivePath),
                default => throw new RuntimeException('Unsupported compressed TAR type: ' . $compressionType),
            };

            return $result;
        } finally {
            @unlink($temporaryTarPath);
        }
    }

    /**
     * Allocates one temporary file path, optionally with a stable suffix.
     *
     * @param string $suffix Optional suffix such as `.tar`.
     * @return string Absolute temporary file path reserved for this process.
     * @throws RuntimeException When no temporary file path can be created.
     */
    private function tempPath(string $suffix = ''): string
    {
        // Try each writable temp root until one reservation succeeds.
        foreach ($this->tempRoots() as $directory) {
            $path = @tempnam($directory, 'rvn-archive-');
            // Skip roots where a temporary reservation could not be created.
            if (!is_string($path) || $path === '') {
                continue;
            }

            // Return immediately when no suffix rewrite is required.
            if ($suffix === '') {
                return $path;
            }

            $suffixedPath = $path . $suffix;
            // Prefer atomic rename when attaching a stable suffix.
            if (@rename($path, $suffixedPath)) {
                return $suffixedPath;
            }

            @unlink($path);
        }

        throw new RuntimeException('Failed to prepare temporary archive path.');
    }

    /**
     * Returns writable temporary directory roots for staged archive mutation work.
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

        // Keep only writable directories; create missing project-local candidates as needed.
        foreach ($candidates as $candidate) {
            // Ignore empty candidate roots from environment lookups.
            if ($candidate === '') {
                continue;
            }

            // Lazily create missing temp roots before writability checks.
            if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
                continue;
            }

            // Skip roots that are not writable by the current runtime user.
            if (!is_writable($candidate)) {
                continue;
            }

            $directories[] = rtrim($candidate, '/\\');
        }

        return array_values(array_unique($directories));
    }
}
