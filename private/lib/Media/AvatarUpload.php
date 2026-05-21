<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/AvatarUpload.php
 * Sanitizes avatar uploads and generates deterministic thumbnail derivatives.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Lib\Transport\Upload;

/**
 * Sanitizes avatar uploads and generates deterministic thumbnail derivatives.
 */
final class AvatarUpload
{
    /** Fixed side length for generated avatar thumbnail JPEG files. */
    private const AVATAR_THUMB_SIZE = 120;
    private ?Upload $uploadTransport = null;

    /**
     * Stores one avatar upload after decode/re-encode metadata stripping.
     *
     * @param array<string, mixed> $upload Raw uploaded-file array from PHP superglobal.
     * @param string $destination Absolute destination path for the stored file.
     * @return string|null null on success, or a user-facing error message on failure.
     */
    public function storeSanitizedUpload(array $upload, string $destination): ?string
    {
        $storeError = $this->storeSanitizedImageUpload($upload, $destination);
        // Abort thumbnail generation when the primary upload failed.
        if ($storeError !== null) {
            return $storeError;
        }

        $thumbnailPath = dirname($destination) . '/' . $this->thumbnailFilename((string) basename($destination));
        $thumbError = $this->storeThumbnail($destination, $thumbnailPath);
        // Roll back both files when thumbnail creation fails to avoid split avatar state.
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
     * @param array<string, mixed> $upload Raw uploaded-file array from PHP superglobal.
     * @param string $destination Absolute destination path for the stored file.
     * @return string|null null on success, or a user-facing error message on failure.
     */
    public function storeSanitizedImageUpload(array $upload, string $destination): ?string
    {
        $validatedUpload = $this->uploadTransport()->validateSingleUpload($upload, 'avatar', [
            'empty_error' => 'Avatar upload appears empty.',
        ]);
        // Transport validation consolidates PHP upload errors into one canonical response.
        if (!(bool) ($validatedUpload['ok'] ?? false)) {
            return (string) ($validatedUpload['error'] ?? 'Avatar upload failed.');
        }
        $validated = $validatedUpload['upload'] ?? null;
        // Validation success must still produce a normalized upload payload array.
        if (!is_array($validated)) {
            return 'Avatar upload failed.';
        }

        $tmpPath = (string) ($validated['tmp_name'] ?? '');

        $extension = strtolower((string) pathinfo($destination, PATHINFO_EXTENSION));
        // Restrict output encoders to the avatar image formats supported by Raven.
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return 'Avatar upload format is not supported.';
        }

        $imagickError = null;
        $stored = false;
        // Prefer Imagick when present because it strips metadata and handles more source edge cases.
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeSanitizedWithImagick($tmpPath, $destination, $extension);
            // Null error indicates write success and prevents fallback rewrites.
            if ($imagickError === null) {
                $stored = true;
            }
        }

        // Fall back to GD only when Imagick did not produce a stored output.
        if (!$stored && function_exists('imagecreatefromstring')) {
            $gdError = $this->storeSanitizedWithGd($tmpPath, $destination, $extension);
            // A null GD error means sanitize/write succeeded.
            if ($gdError === null) {
                $stored = true;
            } else {
                return $gdError;
            }
        }

        // Propagate Imagick-specific failures when fallback did not recover.
        if (!$stored && $imagickError !== null) {
            return $imagickError;
        }

        // If no backend stored the file, required image extensions are missing.
        if (!$stored) {
            return 'Avatar processing requires Imagick or GD extension.';
        }

        @chmod($destination, 0640);

        return null;
    }

    /**
     * Returns the avatar storage directory and creates it when missing.
     *
     * @param string $projectRoot Absolute Raven project root.
     * @return string Absolute avatar storage directory.
     */
    public function storageDirectory(string $projectRoot): string
    {
        $avatarsDir = rtrim($projectRoot, '/\\') . '/public/uploads/avatars';
        // Lazily create the shared avatars directory for first-run environments.
        if (!is_dir($avatarsDir)) {
            @mkdir($avatarsDir, 0775, true);
        }

        return $avatarsDir;
    }

    /**
     * Normalizes one extension token to the supported avatar extension set.
     *
     * @param string $extension Submitted extension token.
     * @return string|null Normalized extension, or null when unsupported.
     */
    public function normalizeExtension(string $extension): ?string
    {
        $normalized = strtolower(trim($extension));
        // Raven canonicalizes jpeg to jpg for deterministic filename generation.
        if ($normalized === 'jpeg') {
            $normalized = 'jpg';
        }

        // Reject unsupported formats before storage path construction.
        if (!in_array($normalized, ['jpg', 'png', 'gif'], true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Builds one deterministic filename from a user-string and extension.
     *
     * @param string $userString User string used as filename stem.
     * @param string $extension Submitted extension token.
     * @return string Canonical avatar filename.
     */
    public function filenameForUserString(string $userString, string $extension): string
    {
        $normalizedExtension = $this->normalizeExtension($extension) ?? 'jpg';
        $normalizedUserString = preg_replace('/[^a-zA-Z0-9]/', '', trim($userString)) ?? '';
        // Fallback keeps generated filenames valid even when user ids/usernames sanitize away.
        if ($normalizedUserString === '') {
            $normalizedUserString = 'avatar';
        }

        return $normalizedUserString . '.' . $normalizedExtension;
    }

    /**
     * Builds the deterministic avatar thumbnail filename.
     *
     * @param string $filename Source avatar filename.
     * @return string Thumbnail filename.
     */
    public function thumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        // Keep thumbnail names stable even when a source filename has no stem.
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }

    /**
     * Stores one avatar upload for a user and returns the stored relative path.
     *
     * Writes the file to `public/uploads/user/{userId}/avatar.ext` under the given
     * project root and generates a 120px square thumbnail alongside it.
     *
     * @param int $userId Numeric user id used as the per-user directory name.
     * @param array<string, mixed> $upload Validated upload payload from the panel file input.
     * @param string $extension Submitted or pre-normalized extension token.
     * @param string $projectRoot Absolute project root for filesystem path resolution.
     * @return array{ok: bool, path?: string, error?: string} Result with stored relative path on success.
     */
    public function storeForUser(int $userId, array $upload, string $extension, string $projectRoot): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        // Caller-facing API returns a structured error instead of throwing for invalid formats.
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Avatar upload format is not supported.'];
        }

        $directory = $this->userDirectory($userId, $projectRoot);
        $filename = 'avatar.' . $normalizedExtension;
        $destination = $directory . '/' . $filename;
        $storeError = $this->storeSanitizedUpload($upload, $destination);
        // Bubble sanitizer/thumbnail failures as user-facing error payloads.
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'path' => 'uploads/user/' . $userId . '/' . $filename];
    }

    /**
     * Deletes one avatar file and its thumbnail from legacy flat storage.
     *
     * @param string $projectRoot Absolute Raven project root.
     * @param string $filename Stored avatar filename.
     * @return void
     */
    public function deleteAvatarFile(string $projectRoot, string $filename): void
    {
        // Normalize to basename to prevent path traversal on deletion.
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $directories = [
            rtrim($projectRoot, '/\\') . '/public/uploads/avatars',
        ];

        // Iterate candidate legacy roots so cleanup remains tolerant of storage layout changes.
        foreach ($directories as $avatarsDir) {
            $path = $avatarsDir . '/' . $safeName;
            // Delete original avatar file when present.
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

    /**
     * Sanitizes and writes an upload using ImageMagick.
     *
     * @param string $tmpPath Uploaded temporary file path.
     * @param string $destination Destination path for sanitized output.
     * @param string $extension Target file extension.
     * @return string|null Null on success, otherwise one user-facing error.
     */
    private function storeSanitizedWithImagick(string $tmpPath, string $destination, string $extension): ?string
    {
        // Imagick exceptions are converted to user-safe errors after cleanup.
        try {
            $image = new \Imagick();
            $image->readImage($tmpPath);
            $image = $image->coalesceImages();

            $format = $extension === 'jpg' ? 'jpeg' : $extension;
            // Sanitize every animation/frame payload consistently before writing.
            foreach ($image as $frame) {
                // Guard type assertions because iterator payloads can vary by Imagick build.
                if ($frame instanceof \Imagick) {
                    // Auto-orient only when the Imagick build exposes that helper.
                    if (method_exists($frame, 'autoOrientImage')) {
                        $frame->autoOrientImage();
                    }
                    $frame->stripImage();
                    $frame->setImageFormat($format);
                    // JPEG outputs get explicit compression settings for predictable file size/quality.
                    if ($format === 'jpeg') {
                        $frame->setImageCompression(\Imagick::COMPRESSION_JPEG);
                        $frame->setImageCompressionQuality(90);
                    }
                }
            }

            // GIFs may contain multiple frames; non-GIF outputs should persist only the first frame.
            if ($format === 'gif') {
                $written = $image->writeImages($destination, true);
            } else {
                $image->setFirstIterator();
                $written = $image->writeImage($destination);
            }

            $image->clear();
            $image->destroy();

            // Validate both write status and on-disk presence before reporting success.
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

    /**
     * Sanitizes and writes an upload using GD fallback.
     *
     * @param string $tmpPath Uploaded temporary file path.
     * @param string $destination Destination path for sanitized output.
     * @param string $extension Target file extension.
     * @return string|null Null on success, otherwise one user-facing error.
     */
    private function storeSanitizedWithGd(string $tmpPath, string $destination, string $extension): ?string
    {
        $bytes = @file_get_contents($tmpPath);
        // Temporary upload bytes must be readable before GD decode can begin.
        if ($bytes === false || $bytes === '') {
            return 'Failed to read uploaded avatar file.';
        }

        $image = @imagecreatefromstring($bytes);
        // Decode failures indicate invalid or unsupported source image payloads.
        if (!is_object($image)) {
            return 'Failed to sanitize avatar upload.';
        }

        // Always release GD image resources even when encoding fails.
        try {
            $written = false;
            // Select encoder by normalized destination extension.
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

        // Require both encoder success and on-disk confirmation before returning success.
        if (!$written || !is_file($destination)) {
            @unlink($destination);
            return 'Failed to store uploaded avatar file.';
        }

        return null;
    }

    /**
     * Generates the deterministic avatar thumbnail from one sanitized source.
     *
     * @param string $sourcePath Source avatar path.
     * @param string $destination Thumbnail destination path.
     * @return string|null Null on success, otherwise one user-facing error.
     */
        private function storeThumbnail(string $sourcePath, string $destination): ?string
    {
        $sourceInfo = @getimagesize($sourcePath);
        // Thumbnail generation requires parseable width/height metadata.
        if (!is_array($sourceInfo) || !isset($sourceInfo[0], $sourceInfo[1])) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = (int) $sourceInfo[0];
        $sourceHeight = (int) $sourceInfo[1];
        // Reject degenerate dimensions before crop/resize math.
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return 'Failed to generate avatar thumbnail.';
        }

        // Tiny avatars are copied directly so quality is not degraded by unnecessary resampling.
        if ($sourceWidth <= self::AVATAR_THUMB_SIZE && $sourceHeight <= self::AVATAR_THUMB_SIZE) {
            // Small avatars should keep exact sanitized bytes for thumb path.
            if (!@copy($sourcePath, $destination) || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        }

        $imagickError = null;
        // Prefer Imagick thumbnail generation when available for robust color/profile handling.
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeThumbnailWithImagick($sourcePath, $destination);
            // Successful Imagick output ends thumbnail processing early.
            if ($imagickError === null) {
                return null;
            }
        }

        // Fall back to GD when available and Imagick was unavailable or failed.
        if (function_exists('imagecreatefromstring')) {
            return $this->storeThumbnailWithGd($sourcePath, $destination);
        }

        // If GD is unavailable, return the more specific Imagick failure when present.
        if ($imagickError !== null) {
            return $imagickError;
        }

        return 'Avatar thumbnail generation requires Imagick or GD extension.';
    }

    /**
     * Generates the avatar thumbnail with ImageMagick.
     *
     * @param string $sourcePath Source avatar path.
     * @param string $destination Thumbnail destination path.
     * @return string|null Null on success, otherwise one user-facing error.
     */
    private function storeThumbnailWithImagick(string $sourcePath, string $destination): ?string
    {
        // Convert Imagick exceptions into stable user-facing error messages.
        try {
            $image = new \Imagick();
            // Restrict to first frame so animated GIF avatars produce deterministic thumbs.
            $image->readImage($sourcePath . '[0]');

            // Auto-orient only when the installed Imagick build supports that API.
            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }

            $sourceWidth = (int) $image->getImageWidth();
            $sourceHeight = (int) $image->getImageHeight();
            // Invalid source dimensions should abort before crop and resize operations.
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
            // Flatten when supported so transparent sources render predictably in JPEG output.
            if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                // Replace the working handle only when layer merge returned a valid image.
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

            // Confirm thumbnail persistence on disk before signaling success.
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

    /**
     * Generates the avatar thumbnail with GD fallback.
     *
     * @param string $sourcePath Source avatar path.
     * @param string $destination Thumbnail destination path.
     * @return string|null Null on success, otherwise one user-facing error.
     */
    private function storeThumbnailWithGd(string $sourcePath, string $destination): ?string
    {
        $bytes = @file_get_contents($sourcePath);
        // Read source bytes first so decode errors can be reported distinctly.
        if ($bytes === false || $bytes === '') {
            return 'Failed to generate avatar thumbnail.';
        }

        $source = @imagecreatefromstring($bytes);
        // GD decode failures mean the sanitized source cannot be interpreted as an image.
        if (!is_object($source)) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        // Guard against broken image resources that report non-positive dimensions.
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
        $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

        $thumbnail = imagecreatetruecolor(self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE);
        // Abort when GD cannot allocate the destination canvas.
        if (!is_object($thumbnail)) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        // Always release both GD resources, regardless of write outcome.
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
            // Crop+resample must complete before encoding JPEG output.
            if (!$written) {
                return 'Failed to generate avatar thumbnail.';
            }

            // Final JPEG write is validated separately for clear error reporting.
            if (!imagejpeg($thumbnail, $destination, 85)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }

        // Ensure the thumbnail file exists after encoder success to avoid false positives.
        if (!is_file($destination)) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }

        return null;
    }

    /**
     * Resolves and creates the per-user upload directory for a given user id.
     *
     * @param int $userId Numeric user id used as the directory name segment.
     * @param string $projectRoot Absolute project root.
     * @return string Absolute path to the user upload directory.
     */
    private function userDirectory(int $userId, string $projectRoot): string
    {
        $directory = rtrim($projectRoot, '/\\') . '/public/uploads/user/' . $userId;
        // Create per-user avatar directories lazily so provisioning stays automatic.
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Returns the shared upload baseline validator.
     *
     * @return Upload
     */
    private function uploadTransport(): Upload
    {
        // Reuse one Upload transport instance to keep validation behavior consistent per request.
        if ($this->uploadTransport instanceof Upload) {
            return $this->uploadTransport;
        }

        $this->uploadTransport = new Upload();
        return $this->uploadTransport;
    }
}
