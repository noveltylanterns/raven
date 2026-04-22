<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/TaxonomyImageScribe.php
 * Write-side upload and filesystem cleanup helper for taxonomy/channel/group images.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Core\Config;
use Raven\Lib\Media\Panel\ImageVariantProcessor;
use Raven\Lib\Media\Panel\TaxonomyImagePathResolver;

/**
 * Owns taxonomy image upload and delete workflows.
 *
 * TaxonomyImageService keeps the read-side config, path, and diff helpers used
 * by panel editors, while this class centralizes filesystem writes, variant
 * generation, and cleanup for category/tag/channel/group image mutations.
 */
final class TaxonomyImageScribe
{
    private Config $config;
    private string $projectRoot;
    private ImageVariantProcessor $variantProcessor;

    /**
     * Prepares the taxonomy-image scribe for upload and delete operations.
     *
     * @param Config $config Runtime config used for upload constraints and EXIF policy.
     * @param string $projectRoot Project root path used to resolve public upload targets.
     * @return void
     */
    public function __construct(Config $config, string $projectRoot)
    {
        $this->config = $config;
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->variantProcessor = new ImageVariantProcessor($config);
    }

    /**
     * Deletes one or more generated path sets for a taxonomy image target.
     *
     * @param string $taxonomyType Taxonomy/image family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $taxonomyId Record id that owns the files.
     * @param array<int, array<string, string|null>> $pathSets One or more path maps returned by upload operations.
     * @return void
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
     * Deletes stored image files for one taxonomy image target.
     *
     * @param string $taxonomyType Taxonomy/image family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $taxonomyId Record id that owns the files.
     * @param array<int, string>|array<string, string|null> $paths Relative stored paths to remove.
     * @return void
     */
    public function deleteStoredPaths(string $taxonomyType, int $taxonomyId, array $paths): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'groups', 'tags'], true) || $taxonomyId < 1) {
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
     * Stores one uploaded taxonomy image and all generated variants.
     *
     * @param string $taxonomyType Taxonomy/image family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $taxonomyId Record id that owns the files.
     * @param string $slot Image slot name: `cover`, `preview`, or `icon`.
     * @param array<string, mixed> $upload Normalized single upload payload from `$_FILES`.
     * @return array{
     *   ok: bool,
     *   record?: array<string, string|null>,
     *   paths?: array{
     *     cover_image_path?: string,
     *     cover_image_sm_path?: string,
     *     cover_image_md_path?: string,
     *     cover_image_lg_path?: string,
     *     preview_image_path?: string,
     *     preview_image_sm_path?: string,
     *     preview_image_md_path?: string,
     *     preview_image_lg_path?: string,
     *     icon_image_path?: string,
     *     icon_image_sm_path?: string,
     *     icon_image_md_path?: string,
     *     icon_image_lg_path?: string
     *   },
     *   error?: string
     * }
     */
    public function storeUpload(string $taxonomyType, int $taxonomyId, string $slot, array $upload): array
    {
        if (
            !in_array($taxonomyType, ['categories', 'channels', 'groups', 'tags'], true)
            || $taxonomyId < 1
            || !in_array($slot, ['cover', 'preview', 'icon'], true)
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

        $uploadTarget = strtolower((string) $this->config->get('media.upload_target', 'local'));
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
            $this->variantProcessor->autoOrient($source);

            if ((bool) $this->config->get('media.strip_exif', true)) {
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

            foreach ($this->variantProcessor->variantSpecs() as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->variantProcessor->resolveVariantSize(
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

            $record = TaxonomyImagePathResolver::supportsFilenameStorage($taxonomyType)
                ? [$slot . '_image' => $originalFilename]
                : $paths;

            return [
                'ok' => true,
                'record' => $record,
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

    /**
     * Returns the allowed taxonomy image extensions from config.
     *
     * @return array<int, string> Normalized lower-case extension allowlist.
     */
    private function allowedImageExtensions(): array
    {
        $raw = strtolower(trim((string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png')));
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

    /**
     * Resolves the configured upload-size ceiling for images.
     *
     * @param string $target Logical media target family.
     * @param int $defaultBytes Default byte ceiling when config is unset.
     * @return int Maximum allowed bytes, or `0` when uploads are unlimited.
     */
    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();

        if ($target === 'images') {
            $kb = (int) ($config['media']['max_filesize_kb'] ?? -1);
            if ($kb >= 0) {
                return $kb === 0 ? 0 : max(1, $kb * 1024);
            }
        }

        return max(1, $defaultBytes);
    }

    /**
     * Removes one taxonomy image directory after its last file is deleted.
     *
     * @param string $taxonomyType Taxonomy/image family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $taxonomyId Record id that owns the directory.
     * @return void
     */
    private function removeDirectoryIfEmpty(string $taxonomyType, int $taxonomyId): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'groups', 'tags'], true) || $taxonomyId < 1) {
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
     * Maps PHP upload error codes to user-facing taxonomy image messages.
     *
     * @param int $code PHP upload error code.
     * @return string User-facing validation message.
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
