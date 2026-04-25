<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/UserMediaScribe.php
 * Write-side filesystem helper for user avatar and cover media.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Lib\Media\Panel\AvatarUploadService;

/**
 * Owns user-media filesystem writes for avatar and cover images.
 *
 * Files are stored under the per-user directory `public/uploads/user/{userId}/`,
 * with fixed base filenames: `avatar.ext` for the profile avatar and `cover.ext`
 * for the profile cover image. Thumbnails live alongside as `avatar_thumb.jpg`.
 *
 * UserMediaPathService keeps the read-side URL/template helpers used by panel
 * and public views, while this class centralizes directory creation, sanitized
 * upload writes, and cleanup for user-media mutation paths.
 */
final class UserMediaScribe
{
    private string $projectRoot;
    private AvatarUploadService $avatarUploadService;

    /**
     * Prepares the user-media scribe for filesystem writes under one project root.
     *
     * @param string $projectRoot Project root path used to resolve upload targets.
     * @param AvatarUploadService|null $avatarUploadService Optional low-level upload sanitizer override.
     */
    public function __construct(string $projectRoot, ?AvatarUploadService $avatarUploadService = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->avatarUploadService = $avatarUploadService ?? new AvatarUploadService();
    }

    /**
     * Normalizes one submitted image extension to the canonical avatar/cover allowlist.
     *
     * @param string $extension Submitted or detected extension token.
     * @return string|null Normalized extension, or null when unsupported.
     */
    public function normalizeExtension(string $extension): ?string
    {
        return $this->avatarUploadService->normalizeExtension($extension);
    }

    /**
     * Stores one avatar upload for a user id and returns the stored relative path.
     *
     * The file is written to `uploads/user/{userId}/avatar.ext` under the public root.
     * A 120px square thumbnail is generated alongside as `avatar_thumb.jpg`.
     *
     * @param int $userId Numeric user id used to derive the per-user upload directory.
     * @param array<string, mixed> $upload Validated upload payload from the panel file input.
     * @param string $extension Normalized or raw extension token.
     * @return array{ok: bool, path?: string, error?: string} Result with stored relative path on success.
     */
    public function storeAvatarUpload(int $userId, array $upload, string $extension): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Avatar upload format is not supported.'];
        }

        $directory = $this->userDirectory($userId);
        $filename = 'avatar.' . $normalizedExtension;
        $destination = $directory . '/' . $filename;
        $storeError = $this->avatarUploadService->storeSanitizedUpload($upload, $destination);
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'path' => 'uploads/user/' . $userId . '/' . $filename];
    }

    /**
     * Stores one cover upload for a user id and returns the stored relative path.
     *
     * The file is written to `uploads/user/{userId}/cover.ext` under the public root.
     * No thumbnail is generated for cover images.
     *
     * @param int $userId Numeric user id used to derive the per-user upload directory.
     * @param array<string, mixed> $upload Validated upload payload from the panel file input.
     * @param string $extension Normalized or raw extension token.
     * @return array{ok: bool, path?: string, error?: string} Result with stored relative path on success.
     */
    public function storeCoverUpload(int $userId, array $upload, string $extension): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Cover image upload format is not supported.'];
        }

        $directory = $this->userDirectory($userId);
        $filename = 'cover.' . $normalizedExtension;
        $destination = $directory . '/' . $filename;
        $storeError = $this->avatarUploadService->storeSanitizedImageUpload($upload, $destination);
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'path' => 'uploads/user/' . $userId . '/' . $filename];
    }

    /**
     * Deletes one stored avatar file and its thumbnail derivative.
     *
     * Accepts the relative path value returned by storeAvatarUpload (new format:
     * `uploads/user/{uid}/avatar.ext`) or a legacy bare filename (old flat-directory
     * format) so that rows written before the per-user directory layout still clean up.
     *
     * @param string $storedPath Stored avatar path or legacy filename from the user row.
     * @return void
     */
    public function deleteAvatarFile(string $storedPath): void
    {
        $normalized = trim($storedPath);
        if ($normalized === '') {
            return;
        }

        if (str_contains($normalized, '/')) {
            // New per-user path format: uploads/user/{uid}/avatar.ext
            $absolute = $this->projectRoot . '/public/' . ltrim($normalized, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            // Delete the thumbnail that lives alongside the main file.
            $thumbAbsolute = dirname($absolute) . '/' . $this->avatarUploadService->thumbnailFilename(basename($absolute));
            if (is_file($thumbAbsolute)) {
                @unlink($thumbAbsolute);
            }

            return;
        }

        // Legacy flat filename: look in the old avatars flat directory.
        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $legacyDirectory = $this->projectRoot . '/public/uploads/avatars';
        $path = $legacyDirectory . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }

        $thumbPath = $legacyDirectory . '/' . $this->avatarUploadService->thumbnailFilename($safeName);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
    }

    /**
     * Deletes one stored cover image file.
     *
     * Accepts the relative path value returned by storeCoverUpload, an external
     * URL (skipped — nothing to delete locally), or a legacy bare filename from
     * the old flat-directory layout.
     *
     * @param string $storedPath Stored cover path, legacy filename, or external URL from the user row.
     * @return void
     */
    public function deleteCoverFile(string $storedPath): void
    {
        $normalized = trim($storedPath);
        if ($normalized === '' || preg_match('#^https?://#i', $normalized) === 1) {
            return;
        }

        if (str_contains($normalized, '/')) {
            // Path-based value: resolve directly from the public root.
            $absolute = $this->projectRoot . '/public/' . ltrim($normalized, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            return;
        }

        // Legacy flat filename: look in the old cover flat directory.
        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $legacyDirectory = $this->projectRoot . '/public/uploads/user/cover';
        $path = $legacyDirectory . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Resolves and creates the per-user upload directory for a given user id.
     *
     * Each user's avatar and cover images share one directory at
     * `public/uploads/user/{userId}/` so files are grouped per account.
     *
     * @param int $userId Numeric user id used as the directory name segment.
     * @return string Absolute path to the user upload directory.
     */
    private function userDirectory(int $userId): string
    {
        $directory = $this->projectRoot . '/public/uploads/user/' . $userId;
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
