<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Package.php
 * Shared archive-package helpers for panel upload and export workflows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

/**
 * Shared archive-package helpers for package upload/export workflows.
 *
 * Keeps package-specific concerns such as temporary-file allocation, archive
 * accept rules, export metadata, manifest slug reads, and download streaming in
 * one place while delegating actual compression/extraction to the canonical
 * archive handlers.
 */
final class Package
{
    private string $projectRoot;
    private Extract $extract;
    private Compress $compress;

    /**
     * @param string $projectRoot Absolute Raven project root path.
     * @param Extract|null $extract Archive extraction forwarder override for tests/composition.
     * @param Compress|null $compress Archive compression forwarder override for tests/composition.
     */
    public function __construct(string $projectRoot, ?Extract $extract = null, ?Compress $compress = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->extract = $extract ?? new Extract();
        $this->compress = $compress ?? new Compress();
    }

    /**
     * Returns supported package-archive extensions for upload/import workflows.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function supportedPackageArchiveExtensions(): array
    {
        return $this->extract->supportedPackageExtensions();
    }

    /**
     * Returns supported directory-export archive extensions.
     *
     * These are the formats Raven can build from a theme/extension directory in
     * one pass via the shared archive compression surface.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function supportedExportArchiveExtensions(): array
    {
        return $this->compress->supportedDirectoryArchiveExtensions();
    }

    /**
     * Returns human-facing package upload format labels for panel help text.
     *
     * These labels intentionally keep common alias suffixes visible so upload
     * documentation matches the archive names operators already have on disk.
     *
     * @return array<int, string> Display labels without leading dots.
     */
    public function supportedPackageArchiveDisplayFormats(): array
    {
        return [
            'zip',
            '7z',
            'tar',
            'tar.gz/.tgz',
            'tar.bz2/.tbz2',
            'tar.xz/.txz',
            'tar.zst/.tzst',
        ];
    }

    /**
     * Returns export format options keyed by canonical archive format value.
     *
     * Theme and extension export UIs use this map to keep the dropdown list in
     * sync with the actual directory-archive formats `Compress` can build.
     *
     * @return array<string, string> Canonical format key => human-facing label.
     */
    public function exportArchiveFormatOptions(): array
    {
        return array_combine(
            $this->supportedExportArchiveExtensions(),
            $this->supportedExportArchiveExtensions()
        ) ?: [];
    }

    /**
     * Returns an HTML file-input `accept` value for supported package archives.
     *
     * @return string Comma-delimited `accept` attribute value.
     */
    public function packageArchiveAcceptAttribute(): string
    {
        return $this->extract->packageArchiveAcceptAttribute();
    }

    /**
     * Returns true when one filename uses a supported package-archive suffix.
     *
     * @param string $filename Uploaded/archive filename to inspect.
     * @return bool True when Raven can extract the package.
     */
    public function isSupportedPackageArchiveName(string $filename): bool
    {
        return $this->extract->isSupportedPackageArchiveName($filename);
    }

    /**
     * Extracts an uploaded archive package into the target directory.
     *
     * @param string $tmpPath Absolute path to the uploaded temporary archive file.
     * @param string $targetDirectory Absolute extraction target directory.
     * @return void
     */
    public function extractUploadedArchive(string $tmpPath, string $targetDirectory): void
    {
        $this->extract->extractTo($tmpPath, $targetDirectory);
    }

    /**
     * Returns true when a directory contains at least one non-dot entry.
     *
     * @param string $directory Absolute directory path to inspect.
     * @return bool True when the directory exists and is not empty.
     */
    public function directoryHasFiles(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Builds an archive from a source directory and returns export metadata.
     *
     * Export callers choose one directory-capable format such as `zip`, `7z`,
     * `tar`, `tar.gz`, `tar.bz2`, `tar.xz`, or `tar.zst`. Compression is
     * always routed through the shared `Compress` facade so package workflows no
     * longer hardcode one archive implementation.
     *
     * @param string $sourceDirectory Absolute path to the source directory.
     * @param string $archiveRoot Preferred archive-root folder name inside the archive.
     * @param string $format Requested archive format key.
     * @return array{path: string, format: string, suffix: string, mime_type: string} Export metadata.
     */
    public function buildArchiveFromDirectory(string $sourceDirectory, string $archiveRoot, string $format = 'zip'): array
    {
        $resolvedFormat = $this->normalizeExportFormat($format);
        $suffix = $this->archiveSuffix($resolvedFormat);
        $temporaryArchivePath = $this->allocateTemporaryArchivePath($suffix);
        $this->compress->compressDirectory($sourceDirectory, $archiveRoot, $temporaryArchivePath);

        return [
            'path' => $temporaryArchivePath,
            'format' => $resolvedFormat,
            'suffix' => $suffix,
            'mime_type' => $this->archiveMimeType($resolvedFormat),
        ];
    }

    /**
     * Returns one browser-facing download filename for an archive export.
     *
     * @param string $prefix Download filename stem without timestamp or suffix.
     * @param string $format Archive format key such as `zip` or `tar.gz`.
     * @param string|null $timestamp Optional preformatted timestamp; null uses current UTC time.
     * @return string Browser-facing archive filename.
     */
    public function exportDownloadFilename(string $prefix, string $format, ?string $timestamp = null): string
    {
        $safePrefix = trim(preg_replace('/[^a-z0-9._-]+/i', '-', $prefix) ?? '', '-_.');
        if ($safePrefix === '') {
            $safePrefix = 'package';
        }

        $resolvedFormat = $this->normalizeExportFormat($format);
        $stamp = is_string($timestamp) && trim($timestamp) !== ''
            ? trim($timestamp)
            : gmdate('Ymd-His');

        return $safePrefix . '-' . $stamp . $this->archiveSuffix($resolvedFormat);
    }

    /**
     * Returns the HTTP content type Raven should send for one archive format.
     *
     * @param string $format Archive format key.
     * @return string Response content type.
     */
    public function archiveMimeType(string $format): string
    {
        return match ($this->normalizeExportFormat($format)) {
            'zip' => 'application/zip',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'tar.gz' => 'application/gzip',
            'tar.bz2' => 'application/x-bzip2',
            'tar.xz' => 'application/x-xz',
            'tar.zst' => 'application/zstd',
            default => 'application/octet-stream',
        };
    }

    /**
     * Streams one local file as a download response, then deletes it.
     *
     * @param string $path Absolute path to the local archive/download file.
     * @param string $downloadFilename Browser-facing download filename.
     * @param string $contentType HTTP content type for the response.
     * @return void
     */
    public function streamDownloadFile(string $path, string $downloadFilename, string $contentType): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            @unlink($path);
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        $size = (int) @filesize($path);
        if ($size > 0) {
            header('Content-Length: ' . $size);
        }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        if (fpassthru($stream) === false) {
            http_response_code(500);
        }

        fclose($stream);
        @unlink($path);
    }

    /**
     * Reads a slug value from one manifest file inside a supported package archive.
     *
     * @param string $archivePath Absolute path to the source archive.
     * @param string $manifestFilename Manifest basename such as `ext.json` or `theme.json`.
     * @param int $maxSlugLength Maximum allowed slug length.
     * @return string|null Valid slug string, or null when none can be resolved.
     */
    public function slugFromArchiveManifest(string $archivePath, string $manifestFilename, int $maxSlugLength): ?string
    {
        return $this->extract->slugFromManifest($archivePath, $manifestFilename, $maxSlugLength);
    }

    /**
     * Returns one human-facing upload error message for a package archive input.
     *
     * @param int $code PHP upload error code.
     * @param string $packageLabel Human-facing package label (e.g. `Theme archive`).
     * @return string Human-facing error message.
     */
    public function uploadErrorMessage(int $code, string $packageLabel): string
    {
        $packageLabel = trim($packageLabel);
        if ($packageLabel === '') {
            $packageLabel = 'Package';
        }

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $packageLabel . ' exceeds server upload limits.',
            UPLOAD_ERR_PARTIAL => $packageLabel . ' upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose an archive file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded ' . strtolower($packageLabel) . '.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'Upload failed with an unknown error.',
        };
    }

    /**
     * Allocates one writable temporary file path, optionally with a fixed suffix.
     *
     * @param string $suffix Optional suffix such as `.zip`.
     * @return string Absolute temporary file path reserved for the caller.
     */
    private function allocateTemporaryArchivePath(string $suffix = ''): string
    {
        $candidateDirectories = [
            (string) sys_get_temp_dir(),
            $this->projectRoot . '/.tmp',
            $this->projectRoot . '/.tmp/exports',
        ];

        foreach ($candidateDirectories as $candidateDirectory) {
            $directory = trim($candidateDirectory);
            if ($directory === '') {
                continue;
            }

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                continue;
            }

            if (!is_writable($directory)) {
                continue;
            }

            $path = @tempnam($directory, 'rvn-export-');
            if (!is_string($path) || $path === '') {
                continue;
            }

            if ($suffix === '') {
                @unlink($path);
                return $path;
            }

            $suffixedPath = $path . $suffix;
            if (@rename($path, $suffixedPath)) {
                @unlink($suffixedPath);
                return $suffixedPath;
            }

            @unlink($path);
        }

        throw new \RuntimeException('Failed to allocate temporary archive path.');
    }

    /**
     * Normalizes one requested export format against Raven's supported outputs.
     *
     * @param string $format Raw archive format request.
     * @return string Canonical archive format key.
     * @throws \RuntimeException When the format is unsupported for directory export.
     */
    private function normalizeExportFormat(string $format): string
    {
        $resolved = strtolower(trim($format));
        if ($resolved === 'tgz') {
            $resolved = 'tar.gz';
        } elseif ($resolved === 'tbz2') {
            $resolved = 'tar.bz2';
        } elseif ($resolved === 'txz') {
            $resolved = 'tar.xz';
        } elseif ($resolved === 'tzst') {
            $resolved = 'tar.zst';
        }

        if (!in_array($resolved, $this->supportedExportArchiveExtensions(), true)) {
            throw new \RuntimeException('Unsupported export archive format: ' . $format);
        }

        return $resolved;
    }

    /**
     * Returns the canonical filename suffix for one export format.
     *
     * @param string $format Canonical archive format key.
     * @return string Filename suffix including the leading dot.
     */
    private function archiveSuffix(string $format): string
    {
        return match ($format) {
            'zip' => '.zip',
            '7z' => '.7z',
            'tar' => '.tar',
            'tar.gz' => '.tar.gz',
            'tar.bz2' => '.tar.bz2',
            'tar.xz' => '.tar.xz',
            'tar.zst' => '.tar.zst',
            default => '.bin',
        };
    }
}
