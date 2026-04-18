<?php

declare(strict_types=1);

namespace Raven\Lib\Media\Panel;

/**
 * Sanitizes avatar uploads and generates deterministic thumbnail derivatives.
 */
final class AvatarUploadService
{
    /** Fixed side length for generated avatar thumbnail JPEG files. */
    private const AVATAR_THUMB_SIZE = 120;

    /**
     * Stores one avatar upload after decode/re-encode metadata stripping.
     *
     * Returns `null` on success, otherwise one user-facing error message.
     *
     * @param array<string, mixed> $upload
     */
    public function storeSanitizedUpload(array $upload, string $destination): ?string
    {
        $storeError = $this->storeSanitizedImageUpload($upload, $destination);
        if ($storeError !== null) {
            return $storeError;
        }

        $thumbnailPath = dirname($destination) . '/' . $this->thumbnailFilename((string) basename($destination));
        $thumbError = $this->storeThumbnail($destination, $thumbnailPath);
        if ($thumbError !== null) {
            @unlink($destination);
            @unlink($thumbnailPath);
            return $thumbError;
        }

        @chmod($thumbnailPath, 0640);

        return null;
    }

    /**
     * Stores one sanitized image upload without generating avatar thumbnail derivatives.
     *
     * Returns `null` on success, otherwise one user-facing error message.
     *
     * @param array<string, mixed> $upload
     */
    public function storeSanitizedImageUpload(array $upload, string $destination): ?string
    {
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return 'Failed to read uploaded avatar file.';
        }

        $extension = strtolower((string) pathinfo($destination, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return 'Avatar upload format is not supported.';
        }

        $imagickError = null;
        $stored = false;
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeSanitizedWithImagick($tmpPath, $destination, $extension);
            if ($imagickError === null) {
                $stored = true;
            }
        }

        if (!$stored && function_exists('imagecreatefromstring')) {
            $gdError = $this->storeSanitizedWithGd($tmpPath, $destination, $extension);
            if ($gdError === null) {
                $stored = true;
            } else {
                return $gdError;
            }
        }

        if (!$stored && $imagickError !== null) {
            return $imagickError;
        }

        if (!$stored) {
            return 'Avatar processing requires Imagick or GD extension.';
        }

        @chmod($destination, 0640);

        return null;
    }

    public function storageDirectory(string $projectRoot): string
    {
        $avatarsDir = rtrim($projectRoot, '/\\') . '/public/uploads/user/avatar';
        if (!is_dir($avatarsDir)) {
            @mkdir($avatarsDir, 0775, true);
        }

        return $avatarsDir;
    }

    public function normalizeExtension(string $extension): ?string
    {
        $normalized = strtolower(trim($extension));
        if ($normalized === 'jpeg') {
            $normalized = 'jpg';
        }

        if (!in_array($normalized, ['jpg', 'png', 'gif'], true)) {
            return null;
        }

        return $normalized;
    }

    public function filenameForUserString(string $userString, string $extension): string
    {
        $normalizedExtension = $this->normalizeExtension($extension) ?? 'jpg';
        $normalizedUserString = preg_replace('/[^a-zA-Z0-9]/', '', trim($userString)) ?? '';
        if ($normalizedUserString === '') {
            $normalizedUserString = 'avatar';
        }

        return $normalizedUserString . '.' . $normalizedExtension;
    }

    public function thumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }

    public function deleteAvatarFile(string $projectRoot, string $filename): void
    {
        // Normalize to basename to prevent path traversal on deletion.
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $directories = [
            rtrim($projectRoot, '/\\') . '/public/uploads/user/avatar',
        ];

        foreach ($directories as $avatarsDir) {
            $path = $avatarsDir . '/' . $safeName;
            if (is_file($path)) {
                @unlink($path);
            }

            // Keep thumbnail lifecycle tied to original avatar lifecycle.
            $thumbPath = $avatarsDir . '/' . $this->thumbnailFilename($safeName);
            if (is_file($thumbPath)) {
                @unlink($thumbPath);
            }
        }
    }

    private function storeSanitizedWithImagick(string $tmpPath, string $destination, string $extension): ?string
    {
        try {
            $image = new \Imagick();
            $image->readImage($tmpPath);
            $image = $image->coalesceImages();

            $format = $extension === 'jpg' ? 'jpeg' : $extension;
            foreach ($image as $frame) {
                if ($frame instanceof \Imagick) {
                    if (method_exists($frame, 'autoOrientImage')) {
                        $frame->autoOrientImage();
                    }
                    $frame->stripImage();
                    $frame->setImageFormat($format);
                    if ($format === 'jpeg') {
                        $frame->setImageCompression(\Imagick::COMPRESSION_JPEG);
                        $frame->setImageCompressionQuality(90);
                    }
                }
            }

            if ($format === 'gif') {
                $written = $image->writeImages($destination, true);
            } else {
                $image->setFirstIterator();
                $written = $image->writeImage($destination);
            }

            $image->clear();
            $image->destroy();

            if (!$written || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to store uploaded avatar file.';
            }

            return null;
        } catch (\Throwable) {
            @unlink($destination);
            return 'Failed to sanitize avatar upload.';
        }
    }

    private function storeSanitizedWithGd(string $tmpPath, string $destination, string $extension): ?string
    {
        $bytes = @file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '') {
            return 'Failed to read uploaded avatar file.';
        }

        $image = @imagecreatefromstring($bytes);
        if (!is_object($image)) {
            return 'Failed to sanitize avatar upload.';
        }

        try {
            $written = false;
            if ($extension === 'jpg' || $extension === 'jpeg') {
                $written = imagejpeg($image, $destination, 90);
            } elseif ($extension === 'png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $written = imagepng($image, $destination, 6);
            } elseif ($extension === 'gif') {
                $written = imagegif($image, $destination);
            }
        } finally {
            imagedestroy($image);
        }

        if (!$written || !is_file($destination)) {
            @unlink($destination);
            return 'Failed to store uploaded avatar file.';
        }

        return null;
    }

    private function storeThumbnail(string $sourcePath, string $destination): ?string
    {
        $sourceInfo = @getimagesize($sourcePath);
        if (!is_array($sourceInfo) || !isset($sourceInfo[0], $sourceInfo[1])) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = (int) $sourceInfo[0];
        $sourceHeight = (int) $sourceInfo[1];
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return 'Failed to generate avatar thumbnail.';
        }

        if ($sourceWidth <= self::AVATAR_THUMB_SIZE && $sourceHeight <= self::AVATAR_THUMB_SIZE) {
            // Small avatars should keep exact sanitized bytes for thumb path.
            if (!@copy($sourcePath, $destination) || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        }

        $imagickError = null;
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeThumbnailWithImagick($sourcePath, $destination);
            if ($imagickError === null) {
                return null;
            }
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->storeThumbnailWithGd($sourcePath, $destination);
        }

        if ($imagickError !== null) {
            return $imagickError;
        }

        return 'Avatar thumbnail generation requires Imagick or GD extension.';
    }

    private function storeThumbnailWithImagick(string $sourcePath, string $destination): ?string
    {
        try {
            $image = new \Imagick();
            // Restrict to first frame so animated GIF avatars produce deterministic thumbs.
            $image->readImage($sourcePath . '[0]');

            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }

            $sourceWidth = (int) $image->getImageWidth();
            $sourceHeight = (int) $image->getImageHeight();
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                $image->clear();
                $image->destroy();
                return 'Failed to generate avatar thumbnail.';
            }

            $cropSize = min($sourceWidth, $sourceHeight);
            $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
            $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

            // Crop to centered square before resizing so thumb fill is always exact 120x120.
            $image->cropImage($cropSize, $cropSize, $cropX, $cropY);
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage(
                self::AVATAR_THUMB_SIZE,
                self::AVATAR_THUMB_SIZE,
                \Imagick::FILTER_LANCZOS,
                1.0,
                true
            );

            $image->setImageBackgroundColor('#ffffff');
            if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flattened instanceof \Imagick) {
                    $image->clear();
                    $image->destroy();
                    $image = $flattened;
                }
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(85);
            $image->stripImage();

            $written = $image->writeImage($destination);
            $image->clear();
            $image->destroy();

            if (!$written || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        } catch (\Throwable) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }
    }

    private function storeThumbnailWithGd(string $sourcePath, string $destination): ?string
    {
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            return 'Failed to generate avatar thumbnail.';
        }

        $source = @imagecreatefromstring($bytes);
        if (!is_object($source)) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
        $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

        $thumbnail = imagecreatetruecolor(self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE);
        if (!is_object($thumbnail)) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        try {
            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefilledrectangle($thumbnail, 0, 0, self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE, $white);

            $written = imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::AVATAR_THUMB_SIZE,
                self::AVATAR_THUMB_SIZE,
                $cropSize,
                $cropSize
            );
            if (!$written) {
                return 'Failed to generate avatar thumbnail.';
            }

            if (!imagejpeg($thumbnail, $destination, 85)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }

        if (!is_file($destination)) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }

        return null;
    }
}
