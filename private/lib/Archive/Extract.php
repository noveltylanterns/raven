<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Extract.php
 * Archive extraction forwarder for ZIP, TAR-family, and RAR packages.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Archive\Types\Bz2;
use Raven\Lib\Archive\Types\Gz;
use Raven\Lib\Archive\Types\Rar;
use Raven\Lib\Archive\Types\Tar;
use Raven\Lib\Archive\Types\Xz;
use Raven\Lib\Archive\Types\Zip;
use Raven\Lib\Archive\Types\Zst;
use RuntimeException;

/**
 * Routes archive extraction requests to the canonical format handlers.
 *
 * This service centralizes package-archive format detection so upload/install
 * workflows can accept more than ZIP without duplicating extension matching,
 * outer-layer decompression, or manifest-reading logic.
 */
final class Extract
{
    private Zip $zip;
    private Tar $tar;
    private Rar $rar;
    private Gz $gz;
    private Bz2 $bz2;
    private Xz $xz;
    private Zst $zst;

    /**
     * @param Zip|null $zip ZIP archive handler override for tests/composition.
     * @param Tar|null $tar TAR archive handler override for tests/composition.
     * @param Rar|null $rar RAR archive handler override for tests/composition.
     * @param Gz|null $gz Gzip decompression handler override for tests/composition.
     * @param Bz2|null $bz2 Bzip2 decompression handler override for tests/composition.
     * @param Xz|null $xz XZ decompression handler override for tests/composition.
     * @param Zst|null $zst Zstandard decompression handler override for tests/composition.
     */
    public function __construct(
        ?Zip $zip = null,
        ?Tar $tar = null,
        ?Rar $rar = null,
        ?Gz $gz = null,
        ?Bz2 $bz2 = null,
        ?Xz $xz = null,
        ?Zst $zst = null
    ) {
        $this->zip = $zip ?? new Zip();
        $this->tar = $tar ?? new Tar();
        $this->rar = $rar ?? new Rar();
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
            '.rar',
            'application/zip',
            'application/x-tar',
            'application/gzip',
            'application/x-gzip',
            'application/x-bzip2',
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
            'rar' => $this->rar->extractTo($archivePath, $targetDir),
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
            'rar' => $this->rar->extractFile($archivePath, $entryName, $targetPath),
            default => throw new RuntimeException('Unsupported archive type: ' . $archivePath),
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
}
