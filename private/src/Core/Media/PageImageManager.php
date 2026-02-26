<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Media/PageImageManager.php
 * ImageMagick-backed service for per-entry gallery upload processing.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Media;

use Imagick;
use Raven\Core\Config;
use Raven\Core\Security\InputSanitizer;
use Raven\Repository\PageImageRepository;

/**
 * Handles upload validation, variant generation, and filesystem cleanup for page galleries.
 */
final class PageImageManager
{
    private Config $config;
    private InputSanitizer $input;
    private PageImageRepository $images;
    private string $projectRoot;

    public function __construct(
        Config $config,
        InputSanitizer $input,
        PageImageRepository $images,
        string $projectRoot
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->images = $images;
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    /**
     * Processes one uploaded image for a page and inserts DB rows for source + variants.
     *
     * @param array<string, mixed>|null $upload
     * @return array{ok: bool, image_id?: int, error?: string}
     */
    public function uploadForPage(int $pageId, ?array $upload): array
    {
        if (!class_exists(Imagick::class)) {
            return [
                'ok' => false,
                'error' => 'Image upload requires Imagick (ImageMagick) extension.',
            ];
        }

        if ($upload === null) {
            return [
                'ok' => false,
                'error' => 'No upload payload provided.',
            ];
        }

        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return [
                'ok' => false,
                'error' => $this->uploadErrorMessage($uploadError),
            ];
        }

        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return [
                'ok' => false,
                'error' => 'Uploaded image could not be validated as an upload.',
            ];
        }

        $uploadTarget = strtolower((string) $this->config->get('media.images.upload_target', 'local'));
        if ($uploadTarget !== 'local') {
            return [
                'ok' => false,
                'error' => 'Only local gallery storage is supported in this build.',
            ];
        }

        $maxBytes = $this->maxUploadFilesizeBytes();
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || ($maxBytes > 0 && $size > $maxBytes)) {
            return [
                'ok' => false,
                'error' => 'Image exceeds configured max filesize.',
            ];
        }

        // Detect real MIME type from bytes, never trust extension alone.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo !== false ? (string) finfo_file($finfo, $tmpPath) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
        ];
        if (!isset($mimeToExt[$detectedMime])) {
            return [
                'ok' => false,
                'error' => 'Only gif/jpg/jpeg/png images are supported.',
            ];
        }
        $canonicalExtension = $mimeToExt[$detectedMime];

        $originalName = (string) ($upload['name'] ?? 'upload');
        $pathInfo = pathinfo($originalName);
        $originalExtension = strtolower((string) ($pathInfo['extension'] ?? ''));
        $originalExtension = $originalExtension === 'jpeg' ? 'jpg' : $originalExtension;

        $allowedExtensions = $this->allowedExtensions();
        if (!in_array($canonicalExtension, $allowedExtensions, true)) {
            return [
                'ok' => false,
                'error' => 'Detected image format is not allowed by current configuration.',
            ];
        }

        // Reject explicit extension mismatch to reduce operator surprise.
        if ($originalExtension !== '' && $originalExtension !== $canonicalExtension) {
            return [
                'ok' => false,
                'error' => 'Uploaded extension does not match detected image bytes.',
            ];
        }

        $dimensions = @getimagesize($tmpPath);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            return [
                'ok' => false,
                'error' => 'Failed to read image dimensions.',
            ];
        }

        $hashSha256 = (string) hash_file('sha256', $tmpPath);
        if ($hashSha256 === '') {
            return [
                'ok' => false,
                'error' => 'Failed to hash uploaded image.',
            ];
        }

        if ($this->images->hasHashForPage($pageId, $hashSha256)) {
            return [
                'ok' => false,
                'error' => 'This image already exists in the page gallery.',
            ];
        }

        $pageDir = $this->projectRoot . '/public/uploads/pages/' . $pageId;
        if (!is_dir($pageDir) && !mkdir($pageDir, 0775, true) && !is_dir($pageDir)) {
            return [
                'ok' => false,
                'error' => 'Failed to create page image directory.',
            ];
        }

        $token = bin2hex(random_bytes(16));
        $baseFilename = 'img_' . $token;
        $originalFilename = $baseFilename . '.' . $canonicalExtension;
        $originalStoredPath = 'uploads/pages/' . $pageId . '/' . $originalFilename;
        $originalAbsolutePath = $this->projectRoot . '/public/' . $originalStoredPath;

        $writtenPaths = [];

        try {
            $source = new Imagick();
            $source->readImage($tmpPath);

            // Normalize multi-frame inputs by using the first frame only.
            $source->setIteratorIndex(0);
            $this->autoOrient($source);

            $stripExif = (bool) $this->config->get('media.images.strip_exif', true);
            if ($stripExif) {
                $source->stripImage();
            }

            $source->setImageFormat($canonicalExtension === 'jpg' ? 'jpeg' : $canonicalExtension);
            if ($canonicalExtension === 'jpg') {
                // Keep JPEG quality high while still reducing payload size.
                $source->setImageCompressionQuality(85);
            }

            if (!$source->writeImage($originalAbsolutePath)) {
                throw new \RuntimeException('Failed to store processed source image.');
            }
            $writtenPaths[] = $originalStoredPath;

            $storedSourceSize = (int) @filesize($originalAbsolutePath);
            $sourceWidth = (int) $source->getImageWidth();
            $sourceHeight = (int) $source->getImageHeight();

            $variantSpecs = $this->variantSpecs();
            $variantRows = [];

            foreach ($variantSpecs as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->resolveVariantSize(
                    $sourceWidth,
                    $sourceHeight,
                    (int) $spec['width'],
                    (int) $spec['height']
                );

                // Resize in "contain" mode (no crop, no padding), and never upscale.
                if ($target['width'] !== $sourceWidth || $target['height'] !== $sourceHeight) {
                    $variant->resizeImage(
                        $target['width'],
                        $target['height'],
                        Imagick::FILTER_LANCZOS,
                        1.0,
                        false
                    );
                }

                if ($canonicalExtension === 'jpg') {
                    $variant->setImageCompressionQuality(85);
                }

                $variantFilename = $baseFilename . '_' . $variantKey . '.' . $canonicalExtension;
                $variantStoredPath = 'uploads/pages/' . $pageId . '/' . $variantFilename;
                $variantAbsolutePath = $this->projectRoot . '/public/' . $variantStoredPath;

                if (!$variant->writeImage($variantAbsolutePath)) {
                    throw new \RuntimeException('Failed to store generated ' . $variantKey . ' variant.');
                }
                $writtenPaths[] = $variantStoredPath;

                $variantRows[] = [
                    'variant_key' => $variantKey,
                    'stored_filename' => $variantFilename,
                    'stored_path' => $variantStoredPath,
                    'mime_type' => $detectedMime,
                    'extension' => $canonicalExtension,
                    'byte_size' => (int) @filesize($variantAbsolutePath),
                    'width' => (int) $variant->getImageWidth(),
                    'height' => (int) $variant->getImageHeight(),
                ];
            }

            $imageTitle = $this->input->text((string) ($pathInfo['filename'] ?? ''), 255);
            $imageRow = [
                'page_id' => $pageId,
                'storage_target' => 'local',
                'original_filename' => $this->input->text($originalName, 255),
                'stored_filename' => $originalFilename,
                'stored_path' => $originalStoredPath,
                'mime_type' => $detectedMime,
                'extension' => $canonicalExtension,
                'byte_size' => $storedSourceSize > 0 ? $storedSourceSize : $size,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
                'hash_sha256' => $hashSha256,
                'status' => 'ready',
                'sort_order' => $this->images->nextSortOrderForPage($pageId),
                'is_cover' => false,
                'is_preview' => false,
                'include_in_gallery' => true,
                'alt_text' => $imageTitle,
                'title_text' => $imageTitle,
                'caption' => '',
                'credit' => '',
                'license' => '',
                'focal_x' => null,
                'focal_y' => null,
            ];

            $imageId = $this->images->insertImageWithVariants($imageRow, $variantRows);

            return [
                'ok' => true,
                'image_id' => $imageId,
            ];
        } catch (\Throwable $exception) {
            // Remove partial files if processing or DB insert fails mid-way.
            foreach ($writtenPaths as $storedPath) {
                $this->deleteStoredPath($storedPath);
            }

            return [
                'ok' => false,
                'error' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Image processing failed.',
            ];
        }
    }

    /**
     * Deletes one image row (and variants) and removes its files from local storage.
     */
    public function deleteImageForPage(int $pageId, int $imageId): bool
    {
        $deleted = $this->images->deleteImageForPage($pageId, $imageId);
        if ($deleted === null) {
            return false;
        }

        /** @var array<int, string> $storedPaths */
        $storedPaths = (array) ($deleted['stored_paths'] ?? []);
        foreach ($storedPaths as $storedPath) {
            $this->deleteStoredPath($storedPath);
        }

        $this->removePageDirectoryIfEmpty($pageId);

        return true;
    }

    /**
     * Deletes every gallery image row for one page and removes local files.
     */
    public function deleteAllForPage(int $pageId): void
    {
        $storedPaths = $this->images->deleteAllForPage($pageId);

        foreach ($storedPaths as $storedPath) {
            $this->deleteStoredPath($storedPath);
        }

        $this->removePageDirectoryIfEmpty($pageId);
    }

    /**
     * Returns normalized extension allowlist from config.
     *
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        $raw = strtolower((string) $this->config->get('media.images.allowed_extensions', 'gif,jpg,jpeg,png'));
        $parts = array_map('trim', explode(',', $raw));

        $allowed = [];
        foreach ($parts as $part) {
            if ($part === 'jpeg') {
                $part = 'jpg';
            }

            if ($part === '') {
                continue;
            }

            if (!preg_match('/^[a-z0-9]+$/', $part)) {
                continue;
            }

            $allowed[$part] = $part;
        }

        return array_values($allowed);
    }

    /**
     * Resolves max upload size in bytes.
     */
    private function maxUploadFilesizeBytes(): int
    {
        $config = $this->config->all();
        $images = $config['media']['images'] ?? null;
        if (is_array($images) && array_key_exists('max_filesize_kb', $images)) {
            $kilobytes = (int) $images['max_filesize_kb'];
            if ($kilobytes > 0) {
                return $kilobytes * 1024;
            }

            if ($kilobytes === 0) {
                // `0` means unlimited file size in the config editor.
                return 0;
            }
        }

        return 10485760;
    }

    /**
     * Returns target dimensions for generated variants.
     *
     * @return array<string, array{width: int, height: int}>
     */
    private function variantSpecs(): array
    {
        return [
            // Keep config keys stable while shortening stored variant keys/filenames.
            'sm' => [
                // `0` means "auto" for this axis (aspect-ratio-preserving contain).
                'width' => max(0, (int) $this->config->get('media.images.small.width', 200)),
                'height' => max(0, (int) $this->config->get('media.images.small.height', 200)),
            ],
            'md' => [
                'width' => max(0, (int) $this->config->get('media.images.med.width', 600)),
                'height' => max(0, (int) $this->config->get('media.images.med.height', 600)),
            ],
            'lg' => [
                'width' => max(0, (int) $this->config->get('media.images.large.width', 1000)),
                'height' => max(0, (int) $this->config->get('media.images.large.height', 1000)),
            ],
        ];
    }

    /**
     * Resolves one contain-style target size from configured max dimensions.
     *
     * Rules:
     * - `0` width or height means "auto" for that axis.
     * - Both `0` means keep source size.
     * - Never upscale above the source size.
     *
     * @return array{width: int, height: int}
     */
    private function resolveVariantSize(
        int $sourceWidth,
        int $sourceHeight,
        int $maxWidth,
        int $maxHeight
    ): array {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return ['width' => 1, 'height' => 1];
        }

        if ($maxWidth <= 0 && $maxHeight <= 0) {
            return ['width' => $sourceWidth, 'height' => $sourceHeight];
        }

        if ($maxWidth <= 0) {
            $scale = min(1.0, $maxHeight / $sourceHeight);
        } elseif ($maxHeight <= 0) {
            $scale = min(1.0, $maxWidth / $sourceWidth);
        } else {
            $scale = min(1.0, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        }

        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        // Clamp to positive configured max bounds when set.
        if ($maxWidth > 0) {
            $targetWidth = min($targetWidth, $maxWidth);
        }
        if ($maxHeight > 0) {
            $targetHeight = min($targetHeight, $maxHeight);
        }

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    /**
     * Converts EXIF orientation into pixel-space rotation/flip changes.
     */
    private function autoOrient(Imagick $image): void
    {
        $orientation = $image->getImageOrientation();

        switch ($orientation) {
            case Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage('#000', 90);
                break;
            case Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage('#000', -90);
                break;
            case Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
            default:
                break;
        }

        // Reset orientation tag to canonical top-left after transform.
        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Deletes one stored relative file path if it resolves inside gallery storage.
     */
    private function deleteStoredPath(string $storedPath): void
    {
        $normalized = ltrim($storedPath, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            return;
        }

        if (!str_starts_with($normalized, 'uploads/pages/')) {
            return;
        }

        $absolutePath = $this->projectRoot . '/public/' . $normalized;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * Removes now-empty page directory after image deletion.
     */
    private function removePageDirectoryIfEmpty(int $pageId): void
    {
        $directory = $this->projectRoot . '/public/uploads/pages/' . $pageId;
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Directory still has files/subdirs, keep it.
            return;
        }

        @rmdir($directory);
    }

    /**
     * Maps PHP upload error codes into user-facing messages.
     */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image exceeds upload size limits.',
            UPLOAD_ERR_PARTIAL => 'Uploaded image was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose an image file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded image.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'Image upload failed with an unknown error.',
        };
    }
}
