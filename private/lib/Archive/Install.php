<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Install.php
 * Shared package-upload workflow helpers for theme and extension installs.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Archive;

use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Upload;

/**
 * Shared package-upload orchestration helpers for panel theme/extension installs.
 *
 * Keeps slug resolution, upload validation, archive extraction, and wrapper
 * directory flattening consistent between the theme and extension managers.
 */
final class Install
{
    private InputSanitizer $input;
    private Upload $uploads;
    private Package $archives;

    /**
     * @param InputSanitizer $input Shared text/path normalization helper.
     * @param Upload $uploads Shared upload validation policy.
     * @param Package $archives Shared archive package helper surface.
     */
    public function __construct(InputSanitizer $input, Upload $uploads, Package $archives)
    {
        $this->input = $input;
        $this->uploads = $uploads;
        $this->archives = $archives;
    }

    /**
     * Validates one uploaded archive payload for a theme/extension install flow.
     *
     * @param mixed $rawUpload Raw `$_FILES[...]` node.
     * @param string $packageLabel Human-facing package label.
     * @param string $collectionLabel Human-facing collection label used in errors.
     * @param int $maxArchiveBytes Maximum allowed archive size in bytes.
     * @return array{ok: bool, error?: string, tmp_path?: string, archive_name?: string}
     */
    public function validateUpload(
        mixed $rawUpload,
        string $packageLabel,
        string $collectionLabel,
        int $maxArchiveBytes = 52428800
    ): array {
        $validatedUpload = $this->uploads->validateSingleUpload($rawUpload, strtolower($packageLabel), [
            'max_bytes' => $maxArchiveBytes,
            'empty_error' => 'Uploaded archive appears empty.',
            'too_large_error' => $packageLabel . ' exceeds the 50MB upload limit.',
        ]);
        if (($validatedUpload['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($validatedUpload['error'] ?? 'Archive upload failed.')];
        }

        /** @var array<string, mixed> $upload */
        $upload = $validatedUpload['upload'] ?? [];
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        $archiveName = $this->input->text((string) ($upload['name'] ?? 'package.zip'), 255);
        if (!$this->archives->supports($archiveName)) {
            return [
                'ok' => false,
                'error' => $collectionLabel . ' must be uploaded as .zip, .tar, .tar.gz/.tgz, .tar.bz2/.tbz2, .tar.xz/.txz, .tar.zst/.tzst, or .7z archives.',
            ];
        }

        return [
            'ok' => true,
            'tmp_path' => $tmpPath,
            'archive_name' => $archiveName,
        ];
    }

    /**
     * Resolves one final install name from manual input, archive metadata, and collision rules.
     *
     * @param string $requestedName Manual slug/directory override from the operator.
     * @param string $archiveName Original uploaded archive filename.
     * @param callable(string): ?string $deriveFromArchive Archive-derived fallback name resolver.
     * @param callable(string): bool $isSafeName Name validator for the target collection.
     * @param callable(string): bool $isReservedName Reserved-name guard for stock items.
     * @param callable(string): ?string $nextAvailableName Auto-rename resolver for slug collisions.
     * @param callable(string): bool $pathExists Filesystem existence probe for the target name.
     * @param string $entityName Human-facing entity label.
     * @param string $safeNameRequirement Human-facing validation error for unsafe names.
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
     * Extracts an uploaded archive into a target directory and validates non-empty output.
     *
     * @param string $tmpPath Absolute path to the uploaded temporary archive.
     * @param string $targetDirectory Absolute target directory.
     * @param callable(string): void $cleanup Cleanup callback for failed extraction.
     * @param string $entityLabel Human-facing entity label for fallback errors.
     * @return string|null Null on success, or one human-facing error message.
     */
    public function extractTo(string $tmpPath, string $targetDirectory, callable $cleanup, string $entityLabel): ?string
    {
        try {
            $this->archives->extractUpload($tmpPath, $targetDirectory);
        } catch (\Throwable $exception) {
            $cleanup($targetDirectory);
            return $exception->getMessage() !== '' ? $exception->getMessage() : ucfirst($entityLabel) . ' upload failed.';
        }

        if (!$this->archives->hasFiles($targetDirectory)) {
            $cleanup($targetDirectory);
            return 'Extracted ' . strtolower($entityLabel) . ' directory is empty.';
        }

        return null;
    }

    /**
     * Normalizes extracted package layout when the archive uses one top-level wrapper directory.
     *
     * @param string $targetDirectory Absolute extraction target directory.
     * @return string|null Null on success, or one human-facing error message.
     */
    public function flattenRoot(string $targetDirectory): ?string
    {
        $entries = $this->entries($targetDirectory);
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

        $innerEntries = $this->entries($innerRoot);
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
     * Reads an extension directory slug from an uploaded archive manifest.
     *
     * Supports manifests at archive root or one wrapper-directory deep.
     *
     * @param string $tmpPath Absolute path to the uploaded temporary archive.
     * @return string|null Valid extension slug, or null when none can be resolved.
     */
    public function extensionSlug(string $tmpPath): ?string
    {
        return $this->archives->manifestSlug($tmpPath, 'ext.json', 119);
    }

    /**
     * Reads a public-theme slug from an uploaded archive manifest.
     *
     * Supports manifests at archive root or one wrapper-directory deep.
     *
     * @param string $tmpPath Absolute path to the uploaded temporary archive.
     * @return string|null Valid theme slug, or null when none can be resolved.
     */
    public function themeSlug(string $tmpPath): ?string
    {
        return $this->archives->manifestSlug($tmpPath, 'theme.json', 63);
    }

    /**
     * Returns one directory listing with dot entries removed.
     *
     * @param string $path Absolute path to the directory to inspect.
     * @return array<int, string>|null Child entry names, or null on read failure.
     */
    private function entries(string $path): ?array
    {
        $entries = scandir($path);
        if (!is_array($entries)) {
            return null;
        }

        return array_values(array_filter($entries, static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));
    }
}
