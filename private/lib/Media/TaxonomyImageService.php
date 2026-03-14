<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Shared taxonomy image upload/storage pipeline with variant management.
 */
final class TaxonomyImageService
{
    private Config $config;
    private string $projectRoot;

    public function __construct(Config $config, string $projectRoot)
    {
        $this->config = $config;
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * @return array<int, string>
     */
    public function allowedImageExtensions(): array
    {
        $raw = strtolower(trim((string) $this->config->get('media.images.allowed_extensions', 'gif,jpg,jpeg,png')));
        if ($raw === '') {
            return [];
        }

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

    public function allowedImageExtensionsLabel(): string
    {
        $allowed = $this->allowedImageExtensions();
        return $allowed === [] ? 'none (uploads disabled)' : implode(', ', $allowed);
    }

    public function maxImageFilesizeKb(): ?int
    {
        $bytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        if ($bytes <= 0) {
            return null;
        }

        return (int) max(1, ceil($bytes / 1024));
    }

    /**
     * @return array<string, array{width: int, height: int}>
     */
    public function imageVariantSpecs(): array
    {
        return [
            'sm' => [
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
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public function imagePathsFromRecord(?array $record): array
    {
        $paths = [];
        foreach ([
            'cover_image_path',
            'cover_image_sm_path',
            'cover_image_md_path',
            'cover_image_lg_path',
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ] as $key) {
            $raw = trim((string) ($record[$key] ?? ''));
            $paths[$key] = $raw !== '' ? $raw : null;
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    public function imageKeysForSlot(string $slot): array
    {
        if ($slot === 'cover') {
            return [
                'cover_image_path',
                'cover_image_sm_path',
                'cover_image_md_path',
                'cover_image_lg_path',
            ];
        }

        return [
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ];
    }

    /**
     * @param array<string, string|null> $currentPaths
     * @param array<string, string|null> $nextPaths
     * @return array<int, string>
     */
    public function removedPaths(array $currentPaths, array $nextPaths): array
    {
        $nextLookup = [];
        foreach ($nextPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized !== '') {
                $nextLookup[$normalized] = true;
            }
        }

        $removed = [];
        foreach ($currentPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized === '' || isset($nextLookup[$normalized])) {
                continue;
            }

            $removed[$normalized] = $normalized;
        }

        return array_values($removed);
    }

    /**
     * @param array<int, array<string, string|null>> $pathSets
     */
    public function cleanupPathSets(string $taxonomyType, int $taxonomyId, array $pathSets): void
    {
        $paths = [];
        foreach ($pathSets as $pathSet) {
            foreach ($pathSet as $path) {
                $normalized = trim((string) $path);
                if ($normalized === '') {
                    continue;
                }

                $paths[$normalized] = $normalized;
            }
        }

        $this->deleteStoredPaths($taxonomyType, $taxonomyId, array_values($paths));
    }

    /**
     * @param array<int, string>|array<string, string|null> $paths
     */
    public function deleteStoredPaths(string $taxonomyType, int $taxonomyId, array $paths): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'tags'], true) || $taxonomyId < 1) {
            return;
        }

        $prefix = 'uploads/' . $taxonomyType . '/' . $taxonomyId . '/';

        foreach ($paths as $path) {
            $normalized = ltrim(trim((string) $path), '/');
            if (
                $normalized === ''
                || str_contains($normalized, '..')
                || str_contains($normalized, "\0")
                || str_contains($normalized, '\\')
                || !str_starts_with($normalized, $prefix)
            ) {
                continue;
            }

            $absolute = $this->projectRoot . '/public/' . $normalized;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $this->removeDirectoryIfEmpty($taxonomyType, $taxonomyId);
    }

    /**
     * @param array<string, mixed> $upload
     * @return array{
     *   ok: bool,
     *   paths?: array{
     *     cover_image_path?: string,
     *     cover_image_sm_path?: string,
     *     cover_image_md_path?: string,
     *     cover_image_lg_path?: string,
     *     preview_image_path?: string,
     *     preview_image_sm_path?: string,
     *     preview_image_md_path?: string,
     *     preview_image_lg_path?: string
     *   },
     *   error?: string
     * }
     */
    public function storeUpload(string $taxonomyType, int $taxonomyId, string $slot, array $upload): array
    {
        if (
            !in_array($taxonomyType, ['categories', 'channels', 'tags'], true)
            || $taxonomyId < 1
            || !in_array($slot, ['cover', 'preview'], true)
        ) {
            return ['ok' => false, 'error' => 'Invalid taxonomy image target.'];
        }

        if (!class_exists(\Imagick::class)) {
            return ['ok' => false, 'error' => 'Image upload requires Imagick (ImageMagick) extension.'];
        }

        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->uploadErrorMessage($uploadError)];
        }

        $tmpPath = trim((string) ($upload['tmp_name'] ?? ''));
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded image could not be validated as an upload.'];
        }

        $uploadTarget = strtolower((string) $this->config->get('media.images.upload_target', 'local'));
        if ($uploadTarget !== 'local') {
            return ['ok' => false, 'error' => 'Only local image storage is supported in this build.'];
        }

        $maxBytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || ($maxBytes > 0 && $size > $maxBytes)) {
            return ['ok' => false, 'error' => 'Image exceeds configured max filesize.'];
        }

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
            return ['ok' => false, 'error' => 'Only gif/jpg/jpeg/png images are supported.'];
        }
        $canonicalExtension = $mimeToExt[$detectedMime];

        $allowedExtensions = $this->allowedImageExtensions();
        if ($allowedExtensions === [] || !in_array($canonicalExtension, $allowedExtensions, true)) {
            return ['ok' => false, 'error' => 'Detected image format is not allowed by current configuration.'];
        }

        $originalName = (string) ($upload['name'] ?? 'upload');
        $pathInfo = pathinfo($originalName);
        $originalExtension = strtolower((string) ($pathInfo['extension'] ?? ''));
        $originalExtension = $originalExtension === 'jpeg' ? 'jpg' : $originalExtension;
        if ($originalExtension !== '' && $originalExtension !== $canonicalExtension) {
            return ['ok' => false, 'error' => 'Uploaded extension does not match detected image bytes.'];
        }

        $dimensions = @getimagesize($tmpPath);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            return ['ok' => false, 'error' => 'Failed to read image dimensions.'];
        }

        $relativeDirectory = 'uploads/' . $taxonomyType . '/' . $taxonomyId;
        $absoluteDirectory = $this->projectRoot . '/public/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            return ['ok' => false, 'error' => 'Failed to create taxonomy image directory.'];
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Failed to initialize image storage token.'];
        }

        $baseFilename = $slot . '_' . $token;
        $originalFilename = $baseFilename . '.' . $canonicalExtension;
        $originalStoredPath = $relativeDirectory . '/' . $originalFilename;
        $originalAbsolutePath = $this->projectRoot . '/public/' . $originalStoredPath;
        $writtenPaths = [];

        try {
            $source = new \Imagick();
            $source->readImage($tmpPath);
            $source->setIteratorIndex(0);
            $this->autoOrient($source);

            if ((bool) $this->config->get('media.images.strip_exif', true)) {
                $source->stripImage();
            }

            $source->setImageFormat($canonicalExtension === 'jpg' ? 'jpeg' : $canonicalExtension);
            if ($canonicalExtension === 'jpg') {
                $source->setImageCompressionQuality(85);
            }

            if (!$source->writeImage($originalAbsolutePath)) {
                throw new \RuntimeException('Failed to store processed source image.');
            }
            @chmod($originalAbsolutePath, 0640);
            $writtenPaths[] = $originalStoredPath;

            $sourceWidth = (int) $source->getImageWidth();
            $sourceHeight = (int) $source->getImageHeight();
            $paths = [
                $slot . '_image_path' => $originalStoredPath,
            ];

            foreach ($this->imageVariantSpecs() as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->resolveVariantSize(
                    $sourceWidth,
                    $sourceHeight,
                    (int) ($spec['width'] ?? 0),
                    (int) ($spec['height'] ?? 0)
                );

                if ($target['width'] !== $sourceWidth || $target['height'] !== $sourceHeight) {
                    $variant->resizeImage(
                        $target['width'],
                        $target['height'],
                        \Imagick::FILTER_LANCZOS,
                        1.0,
                        false
                    );
                }

                if ($canonicalExtension === 'jpg') {
                    $variant->setImageCompressionQuality(85);
                }

                $variantFilename = $baseFilename . '_' . $variantKey . '.' . $canonicalExtension;
                $variantStoredPath = $relativeDirectory . '/' . $variantFilename;
                $variantAbsolutePath = $this->projectRoot . '/public/' . $variantStoredPath;

                if (!$variant->writeImage($variantAbsolutePath)) {
                    throw new \RuntimeException('Failed to store generated image variant.');
                }

                @chmod($variantAbsolutePath, 0640);
                $writtenPaths[] = $variantStoredPath;
                $paths[$slot . '_image_' . $variantKey . '_path'] = $variantStoredPath;
            }

            return [
                'ok' => true,
                'paths' => $paths,
            ];
        } catch (\Throwable $exception) {
            $this->deleteStoredPaths($taxonomyType, $taxonomyId, $writtenPaths);
            return [
                'ok' => false,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Image processing failed.',
            ];
        }
    }

    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();
        $section = $config['media'][$target] ?? null;
        if (is_array($section) && array_key_exists('max_filesize_kb', $section)) {
            $kilobytes = (int) $section['max_filesize_kb'];
            if ($kilobytes > 0) {
                return max(1, $kilobytes * 1024);
            }

            if ($kilobytes === 0) {
                // `0` means unlimited file size in the config editor.
                return 0;
            }
        }

        return max(1, $defaultBytes);
    }

    private function removeDirectoryIfEmpty(string $taxonomyType, int $taxonomyId): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'tags'], true) || $taxonomyId < 1) {
            return;
        }

        $directory = $this->projectRoot . '/public/uploads/' . $taxonomyType . '/' . $taxonomyId;
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            return;
        }

        @rmdir($directory);
    }

    /**
     * @return array{width: int, height: int}
     */
    private function resolveVariantSize(int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight): array
    {
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

        if ($maxWidth > 0) {
            $targetWidth = min($targetWidth, $maxWidth);
        }
        if ($maxHeight > 0) {
            $targetHeight = min($targetHeight, $maxHeight);
        }

        return ['width' => $targetWidth, 'height' => $targetHeight];
    }

    private function autoOrient(\Imagick $image): void
    {
        $orientation = $image->getImageOrientation();
        switch ($orientation) {
            case \Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case \Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage('#000', -90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
            default:
                break;
        }

        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

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
