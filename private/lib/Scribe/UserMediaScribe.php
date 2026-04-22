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
 * UserMediaPathService keeps the read-side URL/template helpers used by panel
 * and public views, while this class centralizes filename generation, storage
 * directory creation, sanitized upload writes, and cleanup for user-media
 * mutation paths.
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
     * @return void
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
     * Stores one avatar upload for a user string and returns the persisted filename.
     *
     * @param string $userString Stable user string used to derive deterministic storage names.
     * @param array<string, mixed> $upload Validated upload payload.
     * @param string $extension Normalized or raw extension token.
     * @return array{ok: bool, filename?: string, error?: string}
     */
    public function storeAvatarUpload(string $userString, array $upload, string $extension): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Avatar upload format is not supported.'];
        }

        $filename = $this->filenameForString($userString, $normalizedExtension, 'avatar');
        $destination = $this->avatarStorageDirectory() . '/' . $filename;
        $storeError = $this->avatarUploadService->storeSanitizedUpload($upload, $destination);
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'filename' => $filename];
    }

    /**
     * Stores one cover upload for a user string and returns the persisted filename.
     *
     * @param string $userString Stable user string used to derive deterministic storage names.
     * @param array<string, mixed> $upload Validated upload payload.
     * @param string $extension Normalized or raw extension token.
     * @return array{ok: bool, filename?: string, error?: string}
     */
    public function storeCoverUpload(string $userString, array $upload, string $extension): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Cover image upload format is not supported.'];
        }

        $filename = $this->filenameForString($userString, $normalizedExtension, 'cover');
        $destination = $this->coverStorageDirectory() . '/' . $filename;
        $storeError = $this->avatarUploadService->storeSanitizedImageUpload($upload, $destination);
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'filename' => $filename];
    }

    /**
     * Deletes one avatar file and its thumbnail derivative.
     *
     * @param string $filename Stored avatar filename value from the user row.
     * @return void
     */
    public function deleteAvatarFile(string $filename): void
    {
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $directory = $this->avatarStorageDirectory();
        $path = $directory . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }

        $thumbPath = $directory . '/' . $this->avatarUploadService->thumbnailFilename($safeName);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
    }

    /**
     * Deletes one stored cover image when it resolves to a local Raven upload path.
     *
     * @param string $coverValue Stored cover-image value from the user row.
     * @return void
     */
    public function deleteCoverFile(string $coverValue): void
    {
        $normalized = trim($coverValue);
        if ($normalized === '' || preg_match('#^https?://#i', $normalized) === 1) {
            return;
        }

        if (str_starts_with($normalized, '/uploads/')) {
            $relative = ltrim($normalized, '/');
            if (!str_starts_with($relative, 'uploads/user/cover/')) {
                return;
            }

            $absolute = $this->projectRoot . '/public/' . $relative;
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            return;
        }

        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $path = $this->coverStorageDirectory() . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Resolves and creates the canonical avatar upload directory on demand.
     *
     * @return string Absolute avatar upload directory path.
     */
    private function avatarStorageDirectory(): string
    {
        $directory = $this->projectRoot . '/public/uploads/avatars';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Resolves and creates the canonical cover-image upload directory on demand.
     *
     * @return string Absolute cover-image upload directory path.
     */
    private function coverStorageDirectory(): string
    {
        $directory = $this->projectRoot . '/public/uploads/user/cover';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Builds one deterministic filename from a user string plus extension.
     *
     * @param string $userString Stable user string for the owning account.
     * @param string $extension Normalized extension token.
     * @param string $fallbackBase Fallback basename when the user string is empty after normalization.
     * @return string Stored filename.
     */
    private function filenameForString(string $userString, string $extension, string $fallbackBase): string
    {
        $base = preg_replace('/[^a-zA-Z0-9]/', '', trim($userString)) ?? '';
        if ($base === '') {
            $base = $fallbackBase;
        }

        $normalizedExtension = strtolower(trim($extension));
        if ($normalizedExtension === 'jpeg') {
            $normalizedExtension = 'jpg';
        }
        if (!in_array($normalizedExtension, ['jpg', 'png', 'gif'], true)) {
            $normalizedExtension = 'jpg';
        }

        return $base . '.' . $normalizedExtension;
    }
}
