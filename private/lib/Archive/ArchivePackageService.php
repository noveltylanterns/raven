<?php

declare(strict_types=1);

namespace Raven\Lib\Archive;

use ZipArchive;

/**
 * Shared ZIP archive helpers for package upload/export workflows.
 */
final class ArchivePackageService
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    public function isSafeZipEntryPath(string $entryName): bool
    {
        $path = str_replace('\\', '/', trim($entryName));
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function extractUploadedZip(string $tmpPath, string $targetDirectory): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($tmpPath);
        if ($opened !== true) {
            throw new \RuntimeException('Failed to read uploaded ZIP archive.');
        }

        try {
            if ($zip->numFiles < 1) {
                throw new \RuntimeException('Archive is empty.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (!is_string($entryName) || !$this->isSafeZipEntryPath($entryName)) {
                    throw new \RuntimeException('Archive contains unsafe file paths.');
                }
            }

            if (!$zip->extractTo($targetDirectory)) {
                throw new \RuntimeException('Failed to extract archive.');
            }
        } finally {
            $zip->close();
        }
    }

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

    public function buildZipArchiveFromDirectory(string $sourceDirectory, string $archiveRoot): string
    {
        $sourceRoot = realpath($sourceDirectory);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException('Source directory could not be resolved.');
        }

        $sanitizedRoot = preg_replace('/[^a-z0-9._-]+/i', '-', $archiveRoot) ?? '';
        $sanitizedRoot = trim($sanitizedRoot, '-_.');
        if ($sanitizedRoot === '') {
            $sanitizedRoot = 'package';
        }

        $tmpArchivePath = $this->allocateTemporaryArchivePath();

        $zip = new ZipArchive();
        $opened = $zip->open($tmpArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmpArchivePath);
            throw new \RuntimeException('Failed to initialize ZIP archive.');
        }

        try {
            if (!$zip->addEmptyDir($sanitizedRoot)) {
                throw new \RuntimeException('Failed to initialize ZIP root directory.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }

                $sourcePath = $item->getPathname();
                $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
                if ($relativePath === '') {
                    continue;
                }

                $zipPath = $sanitizedRoot . '/' . str_replace('\\', '/', $relativePath);
                if ($item->isDir()) {
                    if (!$zip->addEmptyDir($zipPath)) {
                        throw new \RuntimeException('Failed to add directory "' . $relativePath . '" to ZIP archive.');
                    }
                    continue;
                }

                if (!$zip->addFile($sourcePath, $zipPath)) {
                    throw new \RuntimeException('Failed to add file "' . $relativePath . '" to ZIP archive.');
                }
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($tmpArchivePath);
            throw new \RuntimeException($exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to build ZIP archive.');
        }

        $zip->close();
        return $tmpArchivePath;
    }

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

    public function uploadErrorMessage(int $code, string $packageLabel): string
    {
        $packageLabel = trim($packageLabel);
        if ($packageLabel === '') {
            $packageLabel = 'Package';
        }

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $packageLabel . ' exceeds server upload limits.',
            UPLOAD_ERR_PARTIAL => $packageLabel . ' upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose a ZIP file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded ' . strtolower($packageLabel) . '.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'Upload failed with an unknown error.',
        };
    }

    private function allocateTemporaryArchivePath(): string
    {
        $candidateDirectories = [
            (string) sys_get_temp_dir(),
            $this->projectRoot . '/private/tmp',
            $this->projectRoot . '/private/tmp/exports',
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
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        throw new \RuntimeException('Failed to allocate temporary archive path.');
    }
}
