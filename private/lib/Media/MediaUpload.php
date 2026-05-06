<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/MediaUpload.php
 * ImageMagick-backed service for page-scoped media upload processing.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Imagick;
use Raven\Core\Config;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\MediaWrite;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Upload;

/**
 * Handles upload validation, variant generation, and filesystem cleanup for page galleries.
 */
final class MediaUpload
{
    private Config $config;
    private InputSanitizer $input;
    private MediaRead $mediaRead;
    private MediaWrite $mediaWrite;
    private string $projectRoot;
    private ?ImageExifProcessor $exifProcessor = null;
    private ?ImageImagickProcessor $imagickProcessor = null;
    private ?ImageVariantProcessor $variantProcessor = null;
    private ?MediaStorage $storage = null;
    private ?Upload $uploadTransport = null;

    /**
     * Prepares the panel media upload service with split read/write gallery persistence seams.
     *
     * @param Config         $config      Runtime config used for upload-policy and variant settings.
     * @param InputSanitizer $input       Input normalizer used for stored image metadata fields.
     * @param MediaRead      $mediaRead   Read-side media persistence for duplicate checks and ordering.
     * @param MediaWrite     $mediaWrite  Write-side media persistence for insert/delete workflows.
     * @param string         $projectRoot Absolute Raven project root used for upload-path resolution.
     * @return void
     */
    public function __construct(
        Config $config,
        InputSanitizer $input,
        MediaRead $mediaRead,
        MediaWrite $mediaWrite,
        string $projectRoot
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->mediaRead = $mediaRead;
        $this->mediaWrite = $mediaWrite;
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

        $validatedUpload = $this->uploadTransport()->validateSingleUpload($upload, 'image', [
            'max_bytes' => $this->maxUploadFilesizeBytes(),
            'empty_error' => 'Image appears empty.',
            'too_large_error' => 'Image exceeds configured max filesize.',
        ]);
        if (!(bool) ($validatedUpload['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($validatedUpload['error'] ?? 'Image upload failed.'),
            ];
        }
        $validated = $validatedUpload['upload'] ?? null;
        if (!is_array($validated)) {
            return [
                'ok' => false,
                'error' => 'Image upload failed.',
            ];
        }
        $upload = $validated;
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($validatedUpload['size'] ?? 0);

        $uploadTarget = strtolower((string) $this->config->get('media.upload_target', 'local'));
        if ($uploadTarget !== 'local') {
            return [
                'ok' => false,
                'error' => 'Only local gallery storage is supported in this build.',
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

        if ($this->mediaRead->hasHashForPage($pageId, $hashSha256)) {
            return [
                'ok' => false,
                'error' => 'This image already exists in the page gallery.',
            ];
        }

        if (!$this->storage()->ensurePageDirectory($pageId)) {
            return [
                'ok' => false,
                'error' => 'Failed to create page media directory.',
            ];
        }

        $token = bin2hex(random_bytes(16));
        $baseFilename = 'img_' . $token;
        $originalFilename = $baseFilename . '.' . $canonicalExtension;
        $originalStoredPath = $this->storage()->storedPathForFilename($pageId, $originalFilename);
        $originalAbsolutePath = $this->storage()->absolutePublicPath($originalStoredPath);

        $writtenPaths = [];

        try {
            $source = $this->imagickProcessor()->readFirstFrame($tmpPath);
            $this->imagickProcessor()->prepareForWrite(
                $source,
                $canonicalExtension,
                (bool) $this->config->get('media.strip_exif', true),
                $this->exifProcessor()
            );

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
                $variantStoredPath = $this->storage()->storedPathForFilename($pageId, $variantFilename);
                $variantAbsolutePath = $this->storage()->absolutePublicPath($variantStoredPath);

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
                'page' => $pageId,
                'storage_target' => 'local',
                'original_filename' => $this->input->text($originalName, 255),
                'stored_filename' => $originalFilename,
                'stored_path' => $originalStoredPath,
                'mime_type' => $detectedMime,
                'extension' => $canonicalExtension,
                'byte_size' => $storedSourceSize > 0 ? $storedSourceSize : $size,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
                'hash' => $hashSha256,
                'status' => 'ready',
                'sort_order' => $this->mediaRead->nextSortOrderForPage($pageId),
                'include_in_gallery' => true,
                'alt_text' => $imageTitle,
                'title_text' => $imageTitle,
                'caption' => '',
                'credit' => '',
                'license' => '',
                'focal_x' => null,
                'focal_y' => null,
            ];

            $imageId = $this->mediaWrite->insertImageWithVariants($imageRow, $variantRows);

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
        $deleted = $this->mediaWrite->deleteImageForPage($pageId, $imageId);
        if ($deleted === null) {
            return false;
        }

        /** @var array<int, string> $storedPaths */
        $storedPaths = (array) ($deleted['stored_paths'] ?? []);
        foreach ($storedPaths as $storedPath) {
            $this->deleteStoredPath($storedPath);
        }

        $this->removePageDirectory($pageId);

        return true;
    }

    /**
     * Deletes every gallery image row for one page and removes local files.
     */
    public function deleteAllForPage(int $pageId): void
    {
        $storedPaths = $this->mediaWrite->deleteAllForPage($pageId);

        foreach ($storedPaths as $storedPath) {
            $this->deleteStoredPath($storedPath);
        }

        $this->removePageDirectory($pageId);
    }

    /**
     * Returns normalized extension allowlist from config.
     *
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        $raw = strtolower((string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png'));
        $parts = array_map('trim', explode(',', $raw));

        $allowed = [];
        foreach ($parts as $part) {
            if ($part === 'jpeg') {
                $part = 'jpg';
            }

            if ($part === '' || preg_match('/^[a-z0-9]+$/', $part) !== 1) {
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
        $kilobytes = (int) $this->config->get('media.max_filesize_kb', -1);
        if ($kilobytes >= 0) {
            return $kilobytes === 0 ? 0 : max(1, $kilobytes * 1024);
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
        return $this->variantProcessor()->variantSpecs();
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
        return $this->variantProcessor()->resolveVariantSize($sourceWidth, $sourceHeight, $maxWidth, $maxHeight);
    }

    /**
     * Deletes one stored relative file path if it resolves inside gallery storage.
     */
    private function deleteStoredPath(string $storedPath): void
    {
        $this->storage()->deleteStoredPath($storedPath);
    }

    /**
     * Removes now-empty page directory after image deletion.
     */
    private function removePageDirectory(int $pageId): void
    {
        $this->storage()->removePageDirectory($pageId);
    }

    private function exifProcessor(): ImageExifProcessor
    {
        if (!$this->exifProcessor instanceof ImageExifProcessor) {
            $this->exifProcessor = new ImageExifProcessor();
        }

        return $this->exifProcessor;
    }

    /**
     * Returns the shared ImageMagick processing helper.
     *
     * @return ImageImagickProcessor
     */
    private function imagickProcessor(): ImageImagickProcessor
    {
        if (!$this->imagickProcessor instanceof ImageImagickProcessor) {
            $this->imagickProcessor = new ImageImagickProcessor();
        }

        return $this->imagickProcessor;
    }

    /**
     * Returns the gallery variant config/resize helper.
     *
     * @return ImageVariantProcessor
     */
    private function variantProcessor(): ImageVariantProcessor
    {
        if (!$this->variantProcessor instanceof ImageVariantProcessor) {
            $this->variantProcessor = new ImageVariantProcessor($this->config);
        }

        return $this->variantProcessor;
    }

    /**
     * Returns the gallery storage/path helper.
     *
     * @return MediaStorage
     */
    private function storage(): MediaStorage
    {
        if (!$this->storage instanceof MediaStorage) {
            $this->storage = new MediaStorage($this->projectRoot);
        }

        return $this->storage;
    }

    /**
     * Returns the shared upload baseline validator.
     *
     * @return Upload
     */
    private function uploadTransport(): Upload
    {
        if ($this->uploadTransport instanceof Upload) {
            return $this->uploadTransport;
        }

        $this->uploadTransport = new Upload();
        return $this->uploadTransport;
    }
}
