<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Resolves user-media storage paths and public URLs.
 */
final class UserMediaPathService
{
    public function avatarStorageDirectory(string $projectRoot): string
    {
        $directory = rtrim($projectRoot, '/\\') . '/public/uploads/user/avatar';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    public function coverStorageDirectory(string $projectRoot): string
    {
        $directory = rtrim($projectRoot, '/\\') . '/public/uploads/user/cover';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    public function avatarFilenameForString(string $userString, string $extension): string
    {
        return $this->filenameForString($userString, $extension, 'avatar');
    }

    public function coverFilenameForString(string $userString, string $extension): string
    {
        return $this->filenameForString($userString, $extension, 'cover');
    }

    /**
     * @return array{filename: string, url: string, thumb_url: string}
     */
    public function avatarTemplateData(string $projectRoot, string $avatarPath): array
    {
        $avatarFilename = basename(trim($avatarPath));
        if ($avatarFilename === '') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
        $avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;

        return [
            'filename' => $avatarFilename,
            'url' => '/uploads/user/avatar/' . rawurlencode($avatarFilename),
            'thumb_url' => '/uploads/user/avatar/' . rawurlencode($avatarThumbFilename),
        ];
    }

    public function coverPublicUrl(string $projectRoot, string $coverValue): string
    {
        $normalized = trim($coverValue);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $normalized) === 1 || str_starts_with($normalized, '/')) {
            return $normalized;
        }

        $filename = basename($normalized);
        return '/uploads/user/cover/' . rawurlencode($filename);
    }

    public function deleteAvatarFile(string $projectRoot, string $filename): void
    {
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $directories = [
            $this->avatarStorageDirectory($projectRoot),
        ];
        $thumbFilename = $this->thumbnailFilename($safeName);

        foreach ($directories as $directory) {
            $path = $directory . '/' . $safeName;
            if (is_file($path)) {
                @unlink($path);
            }

            $thumbPath = $directory . '/' . $thumbFilename;
            if (is_file($thumbPath)) {
                @unlink($thumbPath);
            }
        }
    }

    public function deleteCoverFile(string $projectRoot, string $coverValue): void
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

            $absolute = rtrim($projectRoot, '/\\') . '/public/' . $relative;
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            return;
        }

        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $directories = [
            $this->coverStorageDirectory($projectRoot),
        ];
        foreach ($directories as $directory) {
            $path = $directory . '/' . $safeName;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function thumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }

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
