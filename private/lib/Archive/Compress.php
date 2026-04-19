<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Compress.php
 * Archive compression forwarder for ZIP and TAR-family package archives.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Archive\Types\Tar;
use Raven\Lib\Archive\Types\Zip;
use RuntimeException;

/**
 * Routes archive-building requests to the canonical format handlers.
 *
 * This facade keeps package/export code out of format-specific branching while
 * still letting Raven expose ZIP and TAR-family archive generation through one
 * stable library surface.
 */
final class Compress
{
    private Zip $zip;
    private Tar $tar;

    /**
     * @param Zip|null $zip ZIP archive handler override for tests/composition.
     * @param Tar|null $tar TAR archive handler override for tests/composition.
     */
    public function __construct(?Zip $zip = null, ?Tar $tar = null)
    {
        $this->zip = $zip ?? new Zip();
        $this->tar = $tar ?? new Tar();
    }

    /**
     * Returns supported multi-file archive extensions for directory compression.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function supportedDirectoryArchiveExtensions(): array
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
        ];
    }

    /**
     * Compresses a source directory into one archive file chosen by output suffix.
     *
     * ZIP archives honor `$archiveRoot` as their internal wrapper-directory name.
     * TAR-family outputs currently preserve the source directory contents directly.
     *
     * @param string $sourceDir Absolute path to the source directory.
     * @param string $archiveRoot Preferred archive root wrapper name where supported.
     * @param string $outputPath Absolute output archive path.
     * @return void
     * @throws RuntimeException When the output suffix is unsupported or archiving fails.
     */
    public function compressDirectory(string $sourceDir, string $archiveRoot, string $outputPath): void
    {
        $type = $this->requireDirectoryArchiveType($outputPath);

        match ($type) {
            'zip' => $this->zip->compressDirectory($sourceDir, $archiveRoot, $outputPath),
            'tar' => $this->tar->compressDirectory($sourceDir, $outputPath),
            'tar.gz' => $this->tar->compressDirectoryGz($sourceDir, $outputPath),
            'tar.bz2' => $this->tar->compressDirectoryBz2($sourceDir, $outputPath),
            'tar.xz' => $this->tar->compressDirectoryXz($sourceDir, $outputPath),
            'tar.zst' => $this->tar->compressDirectoryZst($sourceDir, $outputPath),
            default => throw new RuntimeException('Unsupported archive type: ' . $outputPath),
        };
    }

    /**
     * Returns true when the output filename uses a supported directory-archive suffix.
     *
     * @param string $filename Output filename or archive path to inspect.
     * @return bool True when `compressDirectory()` can handle the requested format.
     */
    public function isSupportedDirectoryArchiveName(string $filename): bool
    {
        return $this->detectDirectoryArchiveType($filename) !== null;
    }

    /**
     * Detects and returns one supported directory-archive type from a filename/path.
     *
     * @param string $archivePath Archive filename or absolute path.
     * @return string|null Canonical archive type key, or null when unsupported.
     */
    private function detectDirectoryArchiveType(string $archivePath): ?string
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
        ] as $suffix => $type) {
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
    private function requireDirectoryArchiveType(string $archivePath): string
    {
        $type = $this->detectDirectoryArchiveType($archivePath);
        if ($type === null) {
            throw new RuntimeException('Unsupported archive type: ' . $archivePath);
        }

        return $type;
    }
}
