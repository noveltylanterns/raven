<?php

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Security\InputSanitizer;
use ZipArchive;

/**
 * Shared package-upload orchestration helpers for panel theme/extension installs.
 */
final class PackageInstallWorkflowService
{
    private InputSanitizer $input;
    private ArchivePackageService $archives;

    public function __construct(InputSanitizer $input, ArchivePackageService $archives)
    {
        $this->input = $input;
        $this->archives = $archives;
    }

    /**
     * @param mixed $rawUpload
     * @return array{ok: bool, error?: string, tmp_path?: string, archive_name?: string}
     */
    public function validateZipUploadPayload(
        mixed $rawUpload,
        string $packageLabel,
        string $collectionLabel,
        int $maxArchiveBytes = 52428800
    ): array {
        if (!is_array($rawUpload)) {
            return ['ok' => false, 'error' => 'No ' . strtolower($packageLabel) . ' payload was received.'];
        }

        $uploadError = (int) ($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->archives->uploadErrorMessage($uploadError, $packageLabel)];
        }

        $tmpPath = (string) ($rawUpload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded archive could not be validated as an HTTP upload.'];
        }

        $archiveName = $this->input->text((string) ($rawUpload['name'] ?? 'package.zip'), 255);
        if (strtolower((string) pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            return ['ok' => false, 'error' => $collectionLabel . ' must be uploaded as .zip archives.'];
        }

        $archiveSize = (int) ($rawUpload['size'] ?? 0);
        if ($archiveSize < 1 || $archiveSize > $maxArchiveBytes) {
            return ['ok' => false, 'error' => $packageLabel . ' exceeds the 50MB upload limit.'];
        }

        return [
            'ok' => true,
            'tmp_path' => $tmpPath,
            'archive_name' => $archiveName,
        ];
    }

    /**
     * @param callable(string): ?string $deriveFromArchive
     * @param callable(string): bool $isSafeName
     * @param callable(string): bool $isReservedName
     * @param callable(string): ?string $nextAvailableName
     * @param callable(string): bool $pathExists
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   name?: string,
     *   initial_name?: string,
     *   renamed?: bool
     * }
     */
    public function resolveInstallName(
        string $requestedName,
        string $archiveName,
        callable $deriveFromArchive,
        callable $isSafeName,
        callable $isReservedName,
        callable $nextAvailableName,
        callable $pathExists,
        string $entityName,
        string $safeNameRequirement
    ): array {
        $name = strtolower(trim($requestedName));
        $manualNameProvided = $name !== '';

        if ($name === '') {
            $derivedName = $deriveFromArchive($archiveName);
            if (!is_string($derivedName) || trim($derivedName) === '') {
                return [
                    'ok' => false,
                    'error' => 'Could not derive a valid ' . strtolower($entityName) . ' name from archive filename.',
                ];
            }

            $name = strtolower(trim($derivedName));
        }

        if (!$isSafeName($name)) {
            return ['ok' => false, 'error' => $safeNameRequirement];
        }

        if ($manualNameProvided && $isReservedName($name)) {
            return [
                'ok' => false,
                'error' => 'That ' . strtolower($entityName) . ' name is reserved by a stock ' . strtolower($entityName) . '.',
            ];
        }

        $initialName = $name;
        if ($pathExists($name)) {
            if ($manualNameProvided) {
                return [
                    'ok' => false,
                    'error' => 'A ' . strtolower($entityName) . ' directory with this name already exists.',
                ];
            }

            $resolvedName = $nextAvailableName($name);
            if (!is_string($resolvedName) || $resolvedName === '') {
                return [
                    'ok' => false,
                    'error' => 'Failed to resolve an available ' . strtolower($entityName) . ' name for this upload.',
                ];
            }

            $name = $resolvedName;
        }

        return [
            'ok' => true,
            'name' => $name,
            'initial_name' => $initialName,
            'renamed' => !$manualNameProvided && $name !== $initialName,
        ];
    }

    /**
     * @param callable(string): void $cleanup
     */
    public function extractIntoTarget(string $tmpPath, string $targetDirectory, callable $cleanup, string $entityLabel): ?string
    {
        try {
            $this->archives->extractUploadedZip($tmpPath, $targetDirectory);
        } catch (\Throwable $exception) {
            $cleanup($targetDirectory);
            return $exception->getMessage() !== '' ? $exception->getMessage() : ucfirst($entityLabel) . ' upload failed.';
        }

        if (!$this->archives->directoryHasFiles($targetDirectory)) {
            $cleanup($targetDirectory);
            return 'Extracted ' . strtolower($entityLabel) . ' directory is empty.';
        }

        return null;
    }

    /**
     * Normalizes extracted package layout when archive uses one top-level wrapper directory.
     */
    public function flattenSingleRootDirectory(string $targetDirectory): ?string
    {
        $entries = $this->scandirEntries($targetDirectory);
        if ($entries === null) {
            return 'Failed to inspect extracted package.';
        }

        if (count($entries) !== 1) {
            return null;
        }

        $innerRoot = $targetDirectory . '/' . $entries[0];
        if (!is_dir($innerRoot)) {
            return null;
        }

        $innerEntries = $this->scandirEntries($innerRoot);
        if ($innerEntries === null) {
            return 'Failed to inspect extracted package root directory.';
        }

        foreach ($innerEntries as $entry) {
            $sourcePath = $innerRoot . '/' . $entry;
            $destinationPath = $targetDirectory . '/' . $entry;
            if (file_exists($destinationPath)) {
                return 'Extracted package contains conflicting file paths.';
            }

            if (!@rename($sourcePath, $destinationPath)) {
                return 'Failed to normalize extracted package structure.';
            }
        }

        if (!@rmdir($innerRoot)) {
            return 'Failed to finalize extracted package structure.';
        }

        return null;
    }

    /**
     * Reads extension directory slug from archive ext.json manifest.
     *
     * Supports archives where ext.json is at root or one wrapper-directory deep.
     */
    public function extensionSlugFromArchiveManifest(string $tmpPath): ?string
    {
        return $this->manifestSlugFromArchive($tmpPath, 'ext.json', 119);
    }

    /**
     * Reads public-theme slug from archive theme.json manifest.
     *
     * Supports archives where theme.json is at root or one wrapper-directory deep.
     */
    public function themeSlugFromArchiveManifest(string $tmpPath): ?string
    {
        return $this->manifestSlugFromArchive($tmpPath, 'theme.json', 63);
    }

    private function manifestSlugFromArchive(string $tmpPath, string $manifestFilename, int $maxSlugLength): ?string
    {
        $manifestFilename = strtolower(trim($manifestFilename));
        if ($manifestFilename === '') {
            return null;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmpPath);
        if ($opened !== true) {
            return null;
        }

        try {
            $slugPattern = '/^[a-z0-9][a-z0-9_-]{0,' . max(0, $maxSlugLength) . '}$/';
            $candidateIndexes = $this->manifestEntryIndexes($zip, $manifestFilename);
            foreach ($candidateIndexes as $candidate) {
                $index = (int) $candidate;
                if ($index < 0) {
                    continue;
                }

                $raw = $zip->getFromIndex($index);
                if (!is_string($raw) || trim($raw) === '') {
                    continue;
                }

                /** @var mixed $decoded */
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $slugRaw = trim((string) ($decoded['slug'] ?? ''));
                if ($slugRaw === '') {
                    continue;
                }

                $slug = strtolower($slugRaw);
                if (preg_match($slugPattern, $slug) === 1) {
                    return $slug;
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * Collects candidate manifest entry indexes in preferred lookup order:
     * - root-level manifest first
     * - one-wrapper-directory manifest second
     *
     * @return array<int, int>
     */
    private function manifestEntryIndexes(ZipArchive $zip, string $manifestFilename): array
    {
        $rootIndexes = [];
        $wrappedIndexes = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (!is_string($entryName) || !$this->archives->isSafeZipEntryPath($entryName)) {
                continue;
            }

            $normalizedEntry = trim(str_replace('\\', '/', $entryName), '/');
            if ($normalizedEntry === '' || strtolower((string) pathinfo($normalizedEntry, PATHINFO_BASENAME)) !== $manifestFilename) {
                continue;
            }

            $directory = trim((string) pathinfo($normalizedEntry, PATHINFO_DIRNAME), '.');
            $depth = $directory === '' ? 0 : substr_count($directory, '/') + 1;
            if ($depth > 1) {
                continue;
            }

            if ($depth === 0) {
                $rootIndexes[] = $index;
                continue;
            }

            $wrappedIndexes[] = $index;
        }

        return [...$rootIndexes, ...$wrappedIndexes];
    }

    /**
     * @return array<int, string>|null
     */
    private function scandirEntries(string $path): ?array
    {
        if (!is_dir($path)) {
            return null;
        }

        $entries = scandir($path);
        if (!is_array($entries)) {
            return null;
        }

        $filtered = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $filtered[] = $entry;
        }

        return $filtered;
    }
}
