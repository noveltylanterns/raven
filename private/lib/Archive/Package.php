<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Package.php
 * Shared archive-package helpers for panel upload and export workflows.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Security\SymlinkGuard;

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
    public function extensions(): array
    {
        return $this->extract->packageExtensions();
    }

    /**
     * Returns supported directory-export archive extensions.
     *
     * These are the formats Raven can build from a theme/extension directory in
     * one pass via the shared archive compression surface.
     *
     * @return array<int, string> Lowercase extension list without leading dots.
     */
    public function exportFormats(): array
    {
        return $this->compress->dirFormats();
    }

    /**
     * Returns human-facing package upload format labels for panel help text.
     *
     * These labels intentionally keep common alias suffixes visible so upload
     * documentation matches the archive names operators already have on disk.
     *
     * @return array<int, string> Display labels without leading dots.
     */
    public function formatLabels(): array
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
    public function exportFormatOptions(): array
    {
        return array_combine(
            $this->exportFormats(),
            $this->exportFormats()
        ) ?: [];
    }

    /**
     * Returns an HTML file-input `accept` value for supported package archives.
     *
     * @return string Comma-delimited `accept` attribute value.
     */
    public function accept(): string
    {
        return $this->extract->packageAccept();
    }

    /**
     * Returns true when one filename uses a supported package-archive suffix.
     *
     * @param string $filename Uploaded/archive filename to inspect.
     * @return bool True when Raven can extract the package.
     */
    public function supports(string $filename): bool
    {
        return $this->extract->supportsPackage($filename);
    }

    /**
     * Extracts an uploaded archive package into the target directory.
     *
     * @param string $tmpPath Absolute path to the uploaded temporary archive file.
     * @param string $targetDirectory Absolute extraction target directory.
     * @param string|null $archiveName Original client filename used for archive-type detection.
     * @return void
     */
    public function extractUpload(string $tmpPath, string $targetDirectory, ?string $archiveName = null): void
    {
        $this->extract->extractTo($tmpPath, $targetDirectory, $archiveName);
    }

    /**
     * Returns true when a directory contains at least one non-dot entry.
     *
     * @param string $directory Absolute directory path to inspect.
     * @return bool True when the directory exists and is not empty.
     */
    public function hasFiles(string $directory): bool
    {
        // Missing directories are treated as empty.
        if (!is_dir($directory)) {
            return false;
        }

        $entries = scandir($directory);
        // Unreadable directories are treated as empty.
        if ($entries === false) {
            return false;
        }

        // Return on first non-dot entry to avoid scanning the full directory.
        foreach ($entries as $entry) {
            // Skip dot entries during emptiness checks.
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
    public function exportDir(string $sourceDirectory, string $archiveRoot, string $format = 'zip'): array
    {
        $resolvedFormat = $this->exportFormat($format);
        $suffix = $this->suffix($resolvedFormat);
        $temporaryArchivePath = $this->tempPath($suffix);
        $this->compress->compressDir($sourceDirectory, $archiveRoot, $temporaryArchivePath);

        return [
            'path' => $temporaryArchivePath,
            'format' => $resolvedFormat,
            'suffix' => $suffix,
            'mime_type' => $this->mimeType($resolvedFormat),
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
    public function downloadName(string $prefix, string $format, ?string $timestamp = null): string
    {
        $safePrefix = trim(preg_replace('/[^a-z0-9._-]+/i', '-', $prefix) ?? '', '-_.');
        // Fall back to a stable filename stem when sanitization strips everything.
        if ($safePrefix === '') {
            $safePrefix = 'package';
        }

        $resolvedFormat = $this->exportFormat($format);
        $stamp = is_string($timestamp) && trim($timestamp) !== ''
            ? trim($timestamp)
            : gmdate('Ymd-His');

        return $safePrefix . '-' . $stamp . $this->suffix($resolvedFormat);
    }

    /**
     * Returns the HTTP content type Raven should send for one archive format.
     *
     * @param string $format Archive format key.
     * @return string Response content type.
     */
    public function mimeType(string $format): string
    {
        return match ($this->exportFormat($format)) {
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
    public function streamDownload(string $path, string $downloadFilename, string $contentType): void
    {
        // Never stream or remove a path that resolves through a symlink.
        SymlinkGuard::assertSymlinkFreePath($path, 'Archive download path');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $stream = fopen($path, 'rb');
        // Abort with a server error when the export file cannot be opened.
        if (!is_resource($stream)) {
            @unlink($path);
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        $size = (int) @filesize($path);
        // Emit content length only when a positive size can be resolved.
        if ($size > 0) {
            header('Content-Length: ' . $size);
        }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // Flag stream failures as server errors after header emission.
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
     * @param string|null $archiveName Original client filename used for archive-type detection.
     * @return string|null Valid slug string, or null when none can be resolved.
     */
    public function manifestSlug(
        string $archivePath,
        string $manifestFilename,
        int $maxSlugLength,
        ?string $archiveName = null
    ): ?string
    {
        return $this->extract->manifestSlug($archivePath, $manifestFilename, $maxSlugLength, $archiveName);
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
        // Normalize blank labels to one stable fallback for error text.
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
    private function tempPath(string $suffix = ''): string
    {
        $candidateDirectories = [
            $this->projectRoot . '/.tmp',
            $this->projectRoot . '/.tmp/exports',
        ];

        // Try each writable temporary root until allocation succeeds.
        foreach ($candidateDirectories as $candidateDirectory) {
            $directory = trim($candidateDirectory);
            // Skip empty candidate roots.
            if ($directory === '') {
                continue;
            }

            // Lazily create missing temp roots before allocation.
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                continue;
            }

            // Skip roots that are not writable by the current runtime user.
            if (!is_writable($directory)) {
                continue;
            }

            $path = @tempnam($directory, 'rvn-export-');
            // Skip roots where tempnam allocation failed.
            if (!is_string($path) || $path === '') {
                continue;
            }

            // Return immediately when no suffix rewrite is requested.
            if ($suffix === '') {
                @unlink($path);
                return $path;
            }

            $suffixedPath = $path . $suffix;
            // Prefer rename so the suffix is part of the reserved path.
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
    private function exportFormat(string $format): string
    {
        $resolved = strtolower(trim($format));
        // Normalize common tar alias extensions to canonical format keys.
        if ($resolved === 'tgz') {
            $resolved = 'tar.gz';
        } elseif ($resolved === 'tbz2') {
            $resolved = 'tar.bz2';
        } elseif ($resolved === 'txz') {
            $resolved = 'tar.xz';
        } elseif ($resolved === 'tzst') {
            $resolved = 'tar.zst';
        }

        // Reject unsupported export formats before compression starts.
        if (!in_array($resolved, $this->exportFormats(), true)) {
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
    private function suffix(string $format): string
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
