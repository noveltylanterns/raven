<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/MediaUpload.php
 * ImageMagick-backed service for page-scoped media upload processing.
 * Docs: https://lanterns.io/raven
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
    private ImageExifProcessor $exifProcessor;
    private ImageImagickProcessor $imagickProcessor;
    private ImageVariantProcessor $variantProcessor;
    private MediaStorage $storage;
    private Upload $uploadTransport;

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
        $this->exifProcessor = new ImageExifProcessor();
        $this->imagickProcessor = new ImageImagickProcessor();
        $this->variantProcessor = new ImageVariantProcessor($this->config);
        $this->storage = new MediaStorage($this->projectRoot);
        $this->uploadTransport = new Upload();
    }

    /**
     * Processes one uploaded image for a page and inserts DB rows for source + variants.
     *
     * @param array<string, mixed>|null $upload
     * @return array{ok: bool, image_id?: int, error?: string}
     */
    public function uploadForPage(int $pageId, ?array $upload): array
    {
        // This pipeline depends on Imagick for source normalization and variant generation.
        if (!class_exists(Imagick::class)) {
            return [
                'ok' => false,
                'error' => 'Image upload requires Imagick (ImageMagick) extension.',
            ];
        }

        // Upload payload must be present before transport-level validation can run.
        if ($upload === null) {
            return [
                'ok' => false,
                'error' => 'No upload payload provided.',
            ];
        }

        $validatedUpload = $this->uploadTransport->validateSingleUpload($upload, 'image', [
            'max_bytes' => $this->maxUploadFilesizeBytes(),
            'empty_error' => 'Image appears empty.',
            'too_large_error' => 'Image exceeds configured max filesize.',
        ]);
        // Transport validation canonicalizes PHP upload errors and size policy failures.
        if (!(bool) ($validatedUpload['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($validatedUpload['error'] ?? 'Image upload failed.'),
            ];
        }
        $validated = $validatedUpload['upload'] ?? null;
        // Successful validation must include a normalized upload payload array.
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
        // Current build supports only local filesystem gallery storage.
        if ($uploadTarget !== 'local') {
            return [
                'ok' => false,
                'error' => 'Only local gallery storage is supported in this build.',
            ];
        }

        // Detect real MIME type from bytes, never trust extension alone.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo !== false ? (string) finfo_file($finfo, $tmpPath) : '';
        // Always close finfo handles once MIME detection is complete.
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
        ];
        // Reject uploads outside the image formats Raven media gallery supports.
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
        // Config allowlist can further restrict formats beyond base MIME support.
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
        // Dimension metadata must parse before variant sizing and DB fields are built.
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            return [
                'ok' => false,
                'error' => 'Failed to read image dimensions.',
            ];
        }

        $hashSha256 = (string) hash_file('sha256', $tmpPath);
        // Hashes back duplicate detection and must be non-empty to continue safely.
        if ($hashSha256 === '') {
            return [
                'ok' => false,
                'error' => 'Failed to hash uploaded image.',
            ];
        }

        // SHA-256 dedupe prevents duplicate source uploads within the same page gallery.
        if ($this->mediaRead->hasHashForPage($pageId, $hashSha256)) {
            return [
                'ok' => false,
                'error' => 'This image already exists in the page gallery.',
            ];
        }

        // Ensure the destination directory exists before any source/variant writes begin.
        if (!$this->storage->ensurePageDirectory($pageId)) {
            return [
                'ok' => false,
                'error' => 'Failed to create page media directory.',
            ];
        }

        $token = bin2hex(random_bytes(16));
        $baseFilename = 'img_' . $token;
        $originalFilename = $baseFilename . '.' . $canonicalExtension;
        $originalStoredPath = $this->storage->storedPathForFilename($pageId, $originalFilename);
        $originalAbsolutePath = $this->storage->absolutePublicPath($originalStoredPath);

        $writtenPaths = [];

        // Wrap processing and persistence so any mid-flight failure can clean written files.
        try {
            $source = $this->imagickProcessor->readFirstFrame($tmpPath);
            $this->imagickProcessor->prepareForWrite(
                $source,
                $canonicalExtension,
                (bool) $this->config->get('media.strip_exif', true),
                $this->exifProcessor
            );

            // Persist the normalized source image first; variants depend on this canonical source.
            if (!$source->writeImage($originalAbsolutePath)) {
                throw new \RuntimeException('Failed to store processed source image.');
            }
            $writtenPaths[] = $originalStoredPath;

            $storedSourceSize = (int) @filesize($originalAbsolutePath);
            $sourceWidth = (int) $source->getImageWidth();
            $sourceHeight = (int) $source->getImageHeight();

            $variantSpecs = $this->variantProcessor->variantSpecs();
            $variantRows = [];

            // Build and persist each configured variant from the normalized source image.
            foreach ($variantSpecs as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->variantProcessor->resolveVariantSize(
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

                // Keep JPEG variant quality consistent with source output defaults.
                if ($canonicalExtension === 'jpg') {
                    $variant->setImageCompressionQuality(85);
                }

                $variantFilename = $baseFilename . '_' . $variantKey . '.' . $canonicalExtension;
                $variantStoredPath = $this->storage->storedPathForFilename($pageId, $variantFilename);
                $variantAbsolutePath = $this->storage->absolutePublicPath($variantStoredPath);

                // Abort and trigger rollback when any variant write fails.
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
                $this->storage->deleteStoredPath($storedPath);
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
     *
     * @param int $pageId Page the image belongs to.
     * @param int $imageId Image record to delete.
     * @return bool True when the row and all stored files were successfully deleted.
     */
    public function deleteImageForPage(int $pageId, int $imageId): bool
    {
        $deleted = $this->mediaWrite->deleteImageForPage($pageId, $imageId);
        // Null indicates no row matched this page/image pair.
        if ($deleted === null) {
            return false;
        }

        /** @var array<int, string> $storedPaths */
        $storedPaths = (array) ($deleted['stored_paths'] ?? []);
        foreach ($storedPaths as $storedPath) {
            $this->storage->deleteStoredPath($storedPath);
        }

        $this->storage->removePageDirectory($pageId);

        return true;
    }

    /**
     * Deletes every gallery image row for one page and removes local files.
     */
    public function deleteAllForPage(int $pageId): void
    {
        $storedPaths = $this->mediaWrite->deleteAllForPage($pageId);

        // Remove each stored file path returned by persistence before pruning empty directories.
        foreach ($storedPaths as $storedPath) {
            $this->storage->deleteStoredPath($storedPath);
        }

        $this->storage->removePageDirectory($pageId);
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
        // Normalize configured tokens into a deduplicated extension allowlist map.
        foreach ($parts as $part) {
            // Canonicalize jpeg to jpg to match runtime MIME normalization.
            if ($part === 'jpeg') {
                $part = 'jpg';
            }

            // Ignore blanks and non-alphanumeric extension tokens from malformed config.
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
        // Non-negative config values are explicit limits, with 0 meaning unlimited.
        if ($kilobytes >= 0) {
            return $kilobytes === 0 ? 0 : max(1, $kilobytes * 1024);
        }

        return 10485760;
    }

}
