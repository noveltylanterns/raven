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
 * accept rules, manifest slug reads, and download streaming in one place while
 * delegating actual compression/extraction to the canonical archive handlers.
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
     * Builds a ZIP archive from a source directory and returns the temporary path.
     *
     * ZIP remains the export format for current theme/extension download flows,
     * but compression is still routed through the shared `Compress` facade so
     * callers stop talking to `ZipArchive` directly.
     *
     * @param string $sourceDirectory Absolute path to the source directory.
     * @param string $archiveRoot Preferred archive-root folder name inside the ZIP.
     * @return string Absolute temporary path to the generated ZIP archive.
     */
    public function buildZipArchiveFromDirectory(string $sourceDirectory, string $archiveRoot): string
    {
        $temporaryArchivePath = $this->allocateTemporaryArchivePath('.zip');
        $this->compress->compressDirectory($sourceDirectory, $archiveRoot, $temporaryArchivePath);

        return $temporaryArchivePath;
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
                return $path;
            }

            $suffixedPath = $path . $suffix;
            if (@rename($path, $suffixedPath)) {
                return $suffixedPath;
            }

            @unlink($path);
        }

        throw new \RuntimeException('Failed to allocate temporary archive path.');
    }
}
