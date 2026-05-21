<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorMeta.php
 * Panel meta-image helper for taxonomy/group/channel file workflows.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Core\Config;
use Raven\Lib\Media\CoverUpload;
use Raven\Lib\Media\CoverValidator;
use Raven\Lib\Media\ImageExifProcessor;
use Raven\Lib\Media\ImageImagickProcessor;
use Raven\Lib\Media\ImageVariantProcessor;
use Raven\Lib\Media\PreviewUpload;
use Raven\Lib\Media\PreviewValidator;

/**
 * Owns cross-callsite meta-image filesystem writes for taxonomy/channel/group entities.
 *
 * Page-gallery SQL persistence lives in `MediaWrite`; this class now focuses only on
 * category/tag/channel/group cover/preview/icon upload + cleanup workflows.
 */
final class EditorMeta
{
    private ?Config $config;
    private ?string $projectRoot;
    private ?ImageVariantProcessor $variantProcessor = null;
    private ?ImageExifProcessor $exifProcessor = null;
    private ?ImageImagickProcessor $imagickProcessor = null;
    private ?CoverValidator $coverValidator = null;
    private ?PreviewValidator $previewValidator = null;
    private ?CoverUpload $coverUpload = null;

    /**
     * Prepares the helper for taxonomy/channel/group meta-image upload workflows.
     *
     * @param Config|null $config      Optional runtime config used by meta-image upload helpers.
     * @param string|null $projectRoot Optional project root path used by meta-image upload helpers.
     * @return void
     */
    public function __construct(?Config $config = null, ?string $projectRoot = null)
    {
        $this->config = $config;
        $normalizedRoot = $projectRoot !== null ? rtrim($projectRoot, '/\\') : null;
        $this->projectRoot = $normalizedRoot !== '' ? $normalizedRoot : null;
    }

    /**
     * Deletes one or more generated path sets for an entity meta-image target.
     *
     * @param string $entityType Entity family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $entityId Record id that owns the files.
     * @param array<int, array<string, string|null>> $pathSets One or more path maps returned by upload operations.
     * @return void
     */
    public function cleanupMetaImagePathSets(string $entityType, int $entityId, array $pathSets): void
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

        $this->deleteMetaImageStoredPaths($entityType, $entityId, array_values($paths));
    }

    /**
     * Deletes stored image files for one entity meta-image target.
     *
     * @param string $entityType Entity family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $entityId Record id that owns the files.
     * @param array<int, string>|array<string, string|null> $paths Relative stored paths to remove.
     * @return void
     */
    public function deleteMetaImageStoredPaths(string $entityType, int $entityId, array $paths): void
    {
        if ($this->projectRoot === null) {
            return;
        }

        if (!in_array($entityType, ['categories', 'channels', 'groups', 'tags'], true) || $entityId < 1) {
            return;
        }

        $prefix = 'uploads/' . $entityType . '/' . $entityId . '/';

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

        $this->removeMetaImageDirectoryIfEmpty($entityType, $entityId);
    }

    /**
     * Stores one uploaded entity meta image and all generated variants.
     *
     * @param string $entityType Entity family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $entityId Record id that owns the files.
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
    public function storeMetaImageUpload(string $entityType, int $entityId, string $slot, array $upload): array
    {
        if ($this->config === null || $this->projectRoot === null) {
            return ['ok' => false, 'error' => 'EditorMeta meta-image runtime is not initialized.'];
        }

        if (
            !in_array($entityType, ['categories', 'channels', 'groups', 'tags'], true)
            || $entityId < 1
            || !in_array($slot, ['cover', 'preview', 'icon'], true)
        ) {
            return ['ok' => false, 'error' => 'Invalid meta image target.'];
        }

        if (!class_exists(\Imagick::class)) {
            return ['ok' => false, 'error' => 'Image upload requires Imagick (ImageMagick) extension.'];
        }

        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->metaImageUploadErrorMessage($uploadError)];
        }

        $tmpPath = trim((string) ($upload['tmp_name'] ?? ''));
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded image could not be validated as an upload.'];
        }

        $uploadTarget = strtolower((string) $this->config->get('media.upload_target', 'local'));
        if ($uploadTarget !== 'local') {
            return ['ok' => false, 'error' => 'Only local image storage is supported in this build.'];
        }

        $validation = $slot === 'cover'
            ? $this->coverValidator()->validateUpload($upload)
            : $this->previewValidator()->validateUpload($upload);
        if (!(bool) ($validation['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($validation['error'] ?? 'Image upload failed.')];
        }

        $canonicalExtension = strtolower((string) ($validation['extension'] ?? ''));
        if ($canonicalExtension === 'jpeg') {
            $canonicalExtension = 'jpg';
        }
        if (!in_array($canonicalExtension, ['jpg', 'png', 'gif'], true)) {
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

        $relativeDirectory = 'uploads/' . $entityType . '/' . $entityId;
        $absoluteDirectory = $this->projectRoot . '/public/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            return ['ok' => false, 'error' => 'Failed to create meta image directory.'];
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
            $source = $this->metaImageImagickProcessor()->readFirstFrame($tmpPath);
            $this->metaImageImagickProcessor()->prepareForWrite(
                $source,
                $canonicalExtension,
                (bool) $this->config->get('media.strip_exif', true),
                $this->metaImageExifProcessor()
            );

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

            foreach ($this->metaImageVariantProcessor()->variantSpecs() as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->metaImageVariantProcessor()->resolveVariantSize(
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

            $record = $this->uploadPolicyForSlot($slot)->recordPayload($entityType, $originalFilename, $paths);

            return [
                'ok' => true,
                'record' => $record,
                'paths' => $paths,
            ];
        } catch (\Throwable $exception) {
            $this->deleteMetaImageStoredPaths($entityType, $entityId, $writtenPaths);

            return [
                'ok' => false,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Image processing failed.',
            ];
        }
    }

    /**
     * Lazily resolves the shared cover-image validator instance.
     *
     * @return CoverValidator Initialized cover validator.
     */
    private function coverValidator(): CoverValidator
    {
        if ($this->coverValidator instanceof CoverValidator) {
            return $this->coverValidator;
        }

        if ($this->config === null) {
            throw new \RuntimeException('EditorMeta meta-image runtime is not initialized.');
        }

        $this->coverValidator = new CoverValidator($this->config);
        return $this->coverValidator;
    }

    /**
     * Lazily resolves the shared preview-image validator instance.
     *
     * @return PreviewValidator Initialized preview validator.
     */
    private function previewValidator(): PreviewValidator
    {
        if ($this->previewValidator instanceof PreviewValidator) {
            return $this->previewValidator;
        }

        if ($this->config === null) {
            throw new \RuntimeException('EditorMeta meta-image runtime is not initialized.');
        }

        $this->previewValidator = new PreviewValidator($this->config);
        return $this->previewValidator;
    }

    /**
     * Lazily resolves the shared cover-image upload policy service.
     *
     * @return CoverUpload Cover-image upload policy helper.
     */
    private function coverUpload(): CoverUpload
    {
        if ($this->coverUpload instanceof CoverUpload) {
            return $this->coverUpload;
        }

        $this->coverUpload = new CoverUpload();
        return $this->coverUpload;
    }

    /**
     * Returns the upload policy service that matches one meta-image slot.
     *
     * @param string $slot Target slot key (`cover`, `icon`, `preview`).
     * @return CoverUpload|PreviewUpload Slot-specific upload policy helper.
     */
    private function uploadPolicyForSlot(string $slot): CoverUpload|PreviewUpload
    {
        if ($slot === 'cover') {
            return $this->coverUpload();
        }

        return new PreviewUpload($slot);
    }

    /**
     * Removes one entity meta-image directory after its last file is deleted.
     *
     * @param string $entityType Entity family: `categories`, `channels`, `groups`, or `tags`.
     * @param int $entityId Record id that owns the directory.
     * @return void
     */
    private function removeMetaImageDirectoryIfEmpty(string $entityType, int $entityId): void
    {
        if ($this->projectRoot === null) {
            return;
        }

        if (!in_array($entityType, ['categories', 'channels', 'groups', 'tags'], true) || $entityId < 1) {
            return;
        }

        $directory = $this->projectRoot . '/public/uploads/' . $entityType . '/' . $entityId;
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
     * Maps PHP upload error codes to user-facing meta-image messages.
     *
     * @param int $code PHP upload error code.
     * @return string User-facing validation message.
     */
    private function metaImageUploadErrorMessage(int $code): string
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

    /**
     * Returns the variant processor used by meta-image upload helpers.
     *
     * @throws \RuntimeException When the scribe was not initialized for meta-image writes.
     * @return ImageVariantProcessor Shared image-variant processor instance.
     */
    private function metaImageVariantProcessor(): ImageVariantProcessor
    {
        if ($this->variantProcessor instanceof ImageVariantProcessor) {
            return $this->variantProcessor;
        }

        if ($this->config === null) {
            throw new \RuntimeException('EditorMeta meta-image runtime is not initialized.');
        }

        $this->variantProcessor = new ImageVariantProcessor($this->config);
        return $this->variantProcessor;
    }

    /**
     * Returns the EXIF processor used by meta-image upload helpers.
     *
     * @return ImageExifProcessor Shared EXIF-orientation processor instance.
     */
    private function metaImageExifProcessor(): ImageExifProcessor
    {
        if ($this->exifProcessor instanceof ImageExifProcessor) {
            return $this->exifProcessor;
        }

        $this->exifProcessor = new ImageExifProcessor();
        return $this->exifProcessor;
    }

    /**
     * Returns the ImageMagick processor used by meta-image upload helpers.
     *
     * @return ImageImagickProcessor Shared ImageMagick pipeline helper.
     */
    private function metaImageImagickProcessor(): ImageImagickProcessor
    {
        if ($this->imagickProcessor instanceof ImageImagickProcessor) {
            return $this->imagickProcessor;
        }

        $this->imagickProcessor = new ImageImagickProcessor();
        return $this->imagickProcessor;
    }
}
