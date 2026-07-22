<?php

/**
 * RAVEN CMS
 * ~/private/lib/Archive/Install.php
 * Shared package-upload workflow helpers for theme and extension installs.
 * Docs: https://lanterns.io/raven
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
        // Abort early when upload normalization/validation failed.
        if (($validatedUpload['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($validatedUpload['error'] ?? 'Archive upload failed.')];
        }

        /** @var array<string, mixed> $upload */
        $upload = $validatedUpload['upload'] ?? [];
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        $archiveName = $this->input->text((string) ($upload['name'] ?? 'package.zip'), 255);
        // Enforce supported archive formats before install-name resolution.
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

        // Auto-derive a name from archive metadata when no manual name was provided.
        if ($name === '') {
            $derivedName = $deriveFromArchive($archiveName);
            // Abort when archive metadata cannot produce a usable name.
            if (!is_string($derivedName) || trim($derivedName) === '') {
                return [
                    'ok' => false,
                    'error' => 'Could not derive a valid ' . strtolower($entityName) . ' name from archive filename.',
                ];
            }

            $name = strtolower(trim($derivedName));
        }

        // Reject names that violate collection safety constraints.
        if (!$isSafeName($name)) {
            return ['ok' => false, 'error' => $safeNameRequirement];
        }

        // Manual names may not override stock/reserved directory names.
        if ($manualNameProvided && $isReservedName($name)) {
            return [
                'ok' => false,
                'error' => 'That ' . strtolower($entityName) . ' name is reserved by a stock ' . strtolower($entityName) . '.',
            ];
        }

        $initialName = $name;
        // Resolve collisions by auto-renaming only when the name was auto-derived.
        if ($pathExists($name)) {
            if ($manualNameProvided) {
                return [
                    'ok' => false,
                    'error' => 'A ' . strtolower($entityName) . ' directory with this name already exists.',
                ];
            }

            $resolvedName = $nextAvailableName($name);
            // Abort when no free collision-safe name can be allocated.
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
     * @param string|null $archiveName Original client filename used for archive-type detection.
     * @return string|null Null on success, or one human-facing error message.
     */
    public function extractTo(
        string $tmpPath,
        string $targetDirectory,
        callable $cleanup,
        string $entityLabel,
        ?string $archiveName = null
    ): ?string
    {
        // Convert extraction failures into one user-facing install error.
        try {
            $this->archives->extractUpload($tmpPath, $targetDirectory, $archiveName);
        } catch (\Throwable $exception) {
            $cleanup($targetDirectory);
            return $exception->getMessage() !== '' ? $exception->getMessage() : ucfirst($entityLabel) . ' upload failed.';
        }

        // Empty extraction results are treated as invalid package uploads.
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
        // Abort when the extraction target cannot be enumerated.
        if ($entries === null) {
            return 'Failed to inspect extracted package.';
        }

        // Flattening applies only to single-wrapper archives.
        if (count($entries) !== 1) {
            return null;
        }

        $innerRoot = $targetDirectory . '/' . $entries[0];
        // Flattening applies only when the sole top-level entry is a directory.
        if (!is_dir($innerRoot)) {
            return null;
        }

        $innerEntries = $this->entries($innerRoot);
        // Abort when the wrapper directory cannot be enumerated.
        if ($innerEntries === null) {
            return 'Failed to inspect extracted package root directory.';
        }

        // Move each wrapped entry up one level into the final install directory.
        foreach ($innerEntries as $entry) {
            $sourcePath = $innerRoot . '/' . $entry;
            $destinationPath = $targetDirectory . '/' . $entry;
            // Abort flattening when a destination path already exists.
            if (file_exists($destinationPath)) {
                return 'Extracted package contains conflicting file paths.';
            }

            // Abort flattening when any move operation fails.
            if (!@rename($sourcePath, $destinationPath)) {
                return 'Failed to normalize extracted package structure.';
            }
        }

        // Remove the now-empty wrapper directory to finalize normalized layout.
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
     * @param string|null $archiveName Original client filename used for archive-type detection.
     * @return string|null Valid extension slug, or null when none can be resolved.
     */
    public function extensionSlug(string $tmpPath, ?string $archiveName = null): ?string
    {
        // Manifest inspection is only a naming optimization; extraction remains the authoritative package validation step.
        try {
            return $this->archives->manifestSlug($tmpPath, 'ext.json', 119, $archiveName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reads a public-theme slug from an uploaded archive manifest.
     *
     * Supports manifests at archive root or one wrapper-directory deep.
     *
     * @param string $tmpPath Absolute path to the uploaded temporary archive.
     * @param string|null $archiveName Original client filename used for archive-type detection.
     * @return string|null Valid theme slug, or null when none can be resolved.
     */
    public function themeSlug(string $tmpPath, ?string $archiveName = null): ?string
    {
        // Manifest inspection is only a naming optimization; extraction remains the authoritative package validation step.
        try {
            return $this->archives->manifestSlug($tmpPath, 'theme.json', 63, $archiveName);
        } catch (\Throwable) {
            return null;
        }
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
        // Return null when directory enumeration fails.
        if (!is_array($entries)) {
            return null;
        }

        return array_values(array_filter($entries, static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));
    }
}
