<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/MediaScribe.php
 * Write-side persistence helper for page-scoped media rows and panel meta images.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Core\Config;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Media\CoverUpload;
use Raven\Lib\Media\CoverValidator;
use Raven\Lib\Media\ImageVariantProcessor;
use Raven\Lib\Media\ImageExifProcessor;
use Raven\Lib\Media\ImageImagickProcessor;
use Raven\Lib\Media\PreviewConfig;
use Raven\Lib\Media\PreviewUpload;
use Raven\Lib\Media\PreviewValidator;

/**
 * Owns page-media mutation writes plus panel meta-image filesystem workflows.
 *
 * MediaRead keeps the read-heavy gallery listing queries, while this class
 * centralizes image insert/update/delete persistence, cover-selection
 * normalization, and transactional cleanup for page-gallery writes. Separate
 * meta-image methods handle category/tag/channel/group cover, preview, and
 * icon uploads without mixing that policy into the page-gallery methods.
 */
final class MediaScribe
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ?Config $config;
    private ?string $projectRoot;
    private ?ImageVariantProcessor $variantProcessor = null;
    private ?ImageExifProcessor $exifProcessor = null;
    private ?ImageImagickProcessor $imagickProcessor = null;
    private ?CoverValidator $coverValidator = null;
    private ?PreviewValidator $previewValidator = null;
    private ?CoverUpload $coverUpload = null;

    /**
     * Prepares the media scribe for page gallery writes and optional meta-image filesystem writes.
     *
     * @param PDO         $db          App database connection used for page-media writes.
     * @param string      $driver      Active PDO driver name used for table resolution and key-return policy.
     * @param string      $prefix      Application table prefix before resolver sanitization.
     * @param Config|null $config      Optional runtime config used by meta-image upload helpers.
     * @param string|null $projectRoot Optional project root path used by meta-image upload helpers.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, ?Config $config = null, ?string $projectRoot = null)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->config = $config;
        $normalizedRoot = $projectRoot !== null ? rtrim($projectRoot, '/\\') : null;
        $this->projectRoot = $normalizedRoot !== '' ? $normalizedRoot : null;
    }

    /**
     * Inserts one source image row and all generated variant rows.
     *
     * @param array<string, scalar|null>                  $image    Normalized source-image payload.
     * @param array<int, array<string, scalar|null>>      $variants Normalized generated variant payload rows.
     * @return int Persisted image id.
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function insertImageWithVariants(array $image, array $variants): int
    {
        $images = $this->table('media');
        $imageVariants = $this->table('media_variants');
        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();

        try {
            if ($this->driver === 'pgsql') {
                // PostgreSQL uses RETURNING for reliable primary-key retrieval.
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $images . ' (
                        page, storage_target, original_filename, stored_filename, stored_path,
                        mime_type, extension, byte_size, width, height, hash,
                        status, sort_order, include_in_gallery, alt_text, title_text, caption, credit, license,
                        focal_x, focal_y, created, updated
                    ) VALUES (
                        :page, :storage_target, :original_filename, :stored_filename, :stored_path,
                        :mime_type, :extension, :byte_size, :width, :height, :hash,
                        :status, :sort_order, :include_in_gallery, :alt_text, :title_text, :caption, :credit, :license,
                        :focal_x, :focal_y, :created, :updated
                    )
                    RETURNING id'
                );
                $insert->execute($this->imageWriteParams($image, $now));

                $imageId = (int) $insert->fetchColumn();
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $images . ' (
                        page, storage_target, original_filename, stored_filename, stored_path,
                        mime_type, extension, byte_size, width, height, hash,
                        status, sort_order, include_in_gallery, alt_text, title_text, caption, credit, license,
                        focal_x, focal_y, created, updated
                    ) VALUES (
                        :page, :storage_target, :original_filename, :stored_filename, :stored_path,
                        :mime_type, :extension, :byte_size, :width, :height, :hash,
                        :status, :sort_order, :include_in_gallery, :alt_text, :title_text, :caption, :credit, :license,
                        :focal_x, :focal_y, :created, :updated
                    )'
                );
                $insert->execute($this->imageWriteParams($image, $now));

                $imageId = (int) $this->db->lastInsertId();
            }

            $insertVariant = $this->db->prepare(
                'INSERT INTO ' . $imageVariants . ' (
                    image, variant_key, stored_filename, stored_path,
                    mime_type, extension, byte_size, width, height, created
                ) VALUES (
                    :image_id, :variant_key, :stored_filename, :stored_path,
                    :mime_type, :extension, :byte_size, :width, :height, :created
                )'
            );

            foreach ($variants as $variant) {
                $insertVariant->execute([
                    ':image_id' => $imageId,
                    ':variant_key' => (string) ($variant['variant_key'] ?? ''),
                    ':stored_filename' => (string) ($variant['stored_filename'] ?? ''),
                    ':stored_path' => (string) ($variant['stored_path'] ?? ''),
                    ':mime_type' => (string) ($variant['mime_type'] ?? ''),
                    ':extension' => (string) ($variant['extension'] ?? ''),
                    ':byte_size' => (int) ($variant['byte_size'] ?? 0),
                    ':width' => (int) ($variant['width'] ?? 0),
                    ':height' => (int) ($variant['height'] ?? 0),
                    ':created' => $now,
                ]);
            }

            $this->db->commit();

            return $imageId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Updates one page's cover image selection and per-image metadata.
     *
     * The `enabled` parameter remains for controller/repository compatibility.
     * The current schema stores no page-level gallery toggle, so this write path
     * only persists cover-image pointers and image-row metadata.
     *
     * @param int                                          $pageId       Page whose gallery metadata is being updated.
     * @param bool                                         $enabled      Unused gallery-enabled flag retained for compatibility.
     * @param array<int, array<string, scalar|null>>       $imageUpdates Per-image metadata and cover selection payload.
     * @return void
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function updateGalleryForPage(int $pageId, bool $enabled, array $imageUpdates): void
    {
        unset($enabled);

        $pages = $this->table('pages');
        $images = $this->table('media');
        $now = gmdate('Y-m-d H:i:s');
        $imageUpdates = $this->canonicalizePrimarySelections($imageUpdates);
        $coverImageId = $this->resolvedPrimaryImageId($pageId, $imageUpdates);

        $this->db->beginTransaction();

        try {
            $updatePage = $this->db->prepare(
                'UPDATE ' . $pages . '
                 SET cover_image = :cover_image,
                     preview_image = :preview_image,
                     updated = :updated
                 WHERE id = :id'
            );
            $updatePage->execute([
                ':cover_image' => $coverImageId,
                ':preview_image' => $coverImageId,
                ':updated' => $now,
                ':id' => $pageId,
            ]);

            if ($imageUpdates !== []) {
                $updateImage = $this->db->prepare(
                    'UPDATE ' . $images . '
                     SET alt_text = :alt_text,
                         title_text = :title_text,
                         caption = :caption,
                         credit = :credit,
                         license = :license,
                         focal_x = :focal_x,
                         focal_y = :focal_y,
                         sort_order = :sort_order,
                         include_in_gallery = :include_in_gallery,
                         updated = :updated
                     WHERE id = :id
                       AND page = :page'
                );

                foreach ($imageUpdates as $imageId => $update) {
                    $updateImage->execute([
                        ':alt_text' => (string) ($update['alt_text'] ?? ''),
                        ':title_text' => (string) ($update['title_text'] ?? ''),
                        ':caption' => (string) ($update['caption'] ?? ''),
                        ':credit' => (string) ($update['credit'] ?? ''),
                        ':license' => (string) ($update['license'] ?? ''),
                        ':focal_x' => $update['focal_x'] ?? null,
                        ':focal_y' => $update['focal_y'] ?? null,
                        ':sort_order' => (int) ($update['sort_order'] ?? 1),
                        ':include_in_gallery' => array_key_exists('include_in_gallery', $update) && empty($update['include_in_gallery']) ? 0 : 1,
                        ':updated' => $now,
                        ':id' => (int) $imageId,
                        ':page' => $pageId,
                    ]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes one gallery image and its variants, returning all stored file paths.
     *
     * @param int $pageId  Owning page id.
     * @param int $imageId Image id to delete.
     * @return array{stored_paths: array<int, string>}|null File paths for filesystem cleanup, or null when the image was not found on the page.
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function deleteImageForPage(int $pageId, int $imageId): ?array
    {
        $pagesTable = $this->table('pages');
        $imagesTable = $this->table('media');
        $variantsTable = $this->table('media_variants');

        $this->db->beginTransaction();

        try {
            $readImage = $this->db->prepare(
                'SELECT stored_path
                 FROM ' . $imagesTable . '
                 WHERE id = :id AND page = :page
                 LIMIT 1'
            );
            $readImage->execute([
                ':id' => $imageId,
                ':page' => $pageId,
            ]);
            $imagePath = $readImage->fetchColumn();

            if ($imagePath === false) {
                $this->db->rollBack();

                return null;
            }

            $readVariants = $this->db->prepare(
                'SELECT stored_path FROM ' . $variantsTable . ' WHERE image = :image_id'
            );
            $readVariants->execute([':image_id' => $imageId]);
            $variantRows = $readVariants->fetchAll() ?: [];

            $deleteVariants = $this->db->prepare(
                'DELETE FROM ' . $variantsTable . ' WHERE image = :image_id'
            );
            $deleteVariants->execute([':image_id' => $imageId]);

            $deleteImage = $this->db->prepare(
                'DELETE FROM ' . $imagesTable . ' WHERE id = :id AND page = :page'
            );
            $deleteImage->execute([
                ':id' => $imageId,
                ':page' => $pageId,
            ]);

            $clearPrimary = $this->db->prepare(
                'UPDATE ' . $pagesTable . '
                 SET cover_image = CASE WHEN cover_image = :image_id THEN NULL ELSE cover_image END,
                     preview_image = CASE WHEN preview_image = :image_id THEN NULL ELSE preview_image END
                 WHERE id = :page'
            );
            $clearPrimary->execute([
                ':image_id' => $imageId,
                ':page' => $pageId,
            ]);

            $this->db->commit();

            $storedPaths = [(string) $imagePath];
            foreach ($variantRows as $variantRow) {
                $storedPaths[] = (string) ($variantRow['stored_path'] ?? '');
            }

            return [
                'stored_paths' => array_values(
                    array_filter($storedPaths, static fn (string $path): bool => $path !== '')
                ),
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes all gallery rows for one page and returns every stored file path.
     *
     * @param int $pageId Page whose gallery rows are being deleted.
     * @return array<int, string> Unique stored paths for filesystem cleanup after the transaction commits.
     * @throws \Throwable Re-throws database failures after rolling back the transaction.
     */
    public function deleteAllForPage(int $pageId): array
    {
        $pagesTable = $this->table('pages');
        $imagesTable = $this->table('media');
        $variantsTable = $this->table('media_variants');

        $this->db->beginTransaction();

        try {
            $readPaths = $this->db->prepare(
                'SELECT i.stored_path AS image_path, v.stored_path AS variant_path
                 FROM ' . $imagesTable . ' i
                 LEFT JOIN ' . $variantsTable . ' v ON v.image = i.id
                 WHERE i.page = :page'
            );
            $readPaths->execute([':page' => $pageId]);
            $rows = $readPaths->fetchAll() ?: [];

            $imageIdsStmt = $this->db->prepare(
                'SELECT id FROM ' . $imagesTable . ' WHERE page = :page'
            );
            $imageIdsStmt->execute([':page' => $pageId]);
            $imageIds = array_map(static fn (mixed $value): int => (int) $value, $imageIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if ($imageIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($imageIds), '?'));
                $deleteVariants = $this->db->prepare(
                    'DELETE FROM ' . $variantsTable . ' WHERE image IN (' . $placeholders . ')'
                );
                $deleteVariants->execute($imageIds);
            }

            $deleteImages = $this->db->prepare(
                'DELETE FROM ' . $imagesTable . ' WHERE page = :page'
            );
            $deleteImages->execute([':page' => $pageId]);

            $clearPrimary = $this->db->prepare(
                'UPDATE ' . $pagesTable . '
                 SET cover_image = NULL,
                     preview_image = NULL
                 WHERE id = :page'
            );
            $clearPrimary->execute([':page' => $pageId]);

            $this->db->commit();

            $paths = [];
            foreach ($rows as $row) {
                $imagePath = (string) ($row['image_path'] ?? '');
                $variantPath = (string) ($row['variant_path'] ?? '');

                if ($imagePath !== '') {
                    $paths[$imagePath] = $imagePath;
                }

                if ($variantPath !== '') {
                    $paths[$variantPath] = $variantPath;
                }
            }

            return array_values($paths);
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
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
            return ['ok' => false, 'error' => 'MediaScribe meta-image runtime is not initialized.'];
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
     * Normalizes source-image insert parameters to the repository's stored schema.
     *
     * @param array<string, scalar|null> $image Normalized source-image payload.
     * @param string                     $now   Shared timestamp for created/updated columns.
     * @return array<string, float|int|string|null> Prepared-statement parameter map.
     */
    private function imageWriteParams(array $image, string $now): array
    {
        return [
            ':page' => (int) ($image['page'] ?? 0),
            ':storage_target' => (string) ($image['storage_target'] ?? 'local'),
            ':original_filename' => (string) ($image['original_filename'] ?? ''),
            ':stored_filename' => (string) ($image['stored_filename'] ?? ''),
            ':stored_path' => (string) ($image['stored_path'] ?? ''),
            ':mime_type' => (string) ($image['mime_type'] ?? ''),
            ':extension' => (string) ($image['extension'] ?? ''),
            ':byte_size' => (int) ($image['byte_size'] ?? 0),
            ':width' => (int) ($image['width'] ?? 0),
            ':height' => (int) ($image['height'] ?? 0),
            ':hash' => (string) ($image['hash'] ?? ''),
            ':status' => (string) ($image['status'] ?? 'ready'),
            ':sort_order' => (int) ($image['sort_order'] ?? 1),
            ':include_in_gallery' => array_key_exists('include_in_gallery', $image) && empty($image['include_in_gallery']) ? 0 : 1,
            ':alt_text' => (string) ($image['alt_text'] ?? ''),
            ':title_text' => (string) ($image['title_text'] ?? ''),
            ':caption' => (string) ($image['caption'] ?? ''),
            ':credit' => (string) ($image['credit'] ?? ''),
            ':license' => (string) ($image['license'] ?? ''),
            ':focal_x' => $image['focal_x'] === null ? null : (float) $image['focal_x'],
            ':focal_y' => $image['focal_y'] === null ? null : (float) $image['focal_y'],
            ':created' => $now,
            ':updated' => $now,
        ];
    }

    /**
     * Enforces one cover selection across a page update payload.
     *
     * @param array<int, array<string, scalar|null>> $imageUpdates Per-image metadata keyed by image id.
     * @return array<int, array<string, scalar|null>> Canonicalized payload with at most one cover image selected.
     */
    private function canonicalizePrimarySelections(array $imageUpdates): array
    {
        if ($imageUpdates === []) {
            return [];
        }

        $orderedImageIds = array_keys($imageUpdates);
        usort($orderedImageIds, static function (int $a, int $b) use ($imageUpdates): int {
            $aSort = (int) ($imageUpdates[$a]['sort_order'] ?? 1);
            $bSort = (int) ($imageUpdates[$b]['sort_order'] ?? 1);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return $a <=> $b;
        });

        $coverWinner = null;
        foreach ($orderedImageIds as $imageId) {
            if (!empty($imageUpdates[$imageId]['is_cover'])) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $imageUpdates[$imageId]['is_cover'] = false;
                }
            }
        }

        return $imageUpdates;
    }

    /**
     * Resolves the primary image id that should be stored on the page row.
     *
     * @param int                                    $pageId       Owning page id.
     * @param array<int, array<string, scalar|null>> $imageUpdates Canonicalized page-media update payload.
     * @return int|null Selected cover image id, existing cover image id, or null when none applies.
     */
    private function resolvedPrimaryImageId(int $pageId, array $imageUpdates): ?int
    {
        foreach ($this->canonicalizePrimarySelections($imageUpdates) as $imageId => $imageUpdate) {
            if (!empty($imageUpdate['is_cover'])) {
                return (int) $imageId;
            }
        }

        $pages = $this->table('pages');
        $images = $this->table('media');
        $stmt = $this->db->prepare(
            'SELECT p.cover_image
             FROM ' . $pages . ' p
             WHERE p.id = :id
               AND (
                    p.cover_image IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM ' . $images . ' i
                        WHERE i.id = p.cover_image
                          AND i.page = p.id
                    )
               )
             LIMIT 1'
        );
        $stmt->execute([':id' => $pageId]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        $imageId = (int) $value;

        return $imageId > 0 ? $imageId : null;
    }

    private function coverValidator(): CoverValidator
    {
        if ($this->coverValidator instanceof CoverValidator) {
            return $this->coverValidator;
        }

        if ($this->config === null) {
            throw new \RuntimeException('MediaScribe meta-image runtime is not initialized.');
        }

        $this->coverValidator = new CoverValidator($this->config);
        return $this->coverValidator;
    }

    private function previewValidator(): PreviewValidator
    {
        if ($this->previewValidator instanceof PreviewValidator) {
            return $this->previewValidator;
        }

        if ($this->config === null) {
            throw new \RuntimeException('MediaScribe meta-image runtime is not initialized.');
        }

        $this->previewValidator = new PreviewValidator($this->config);
        return $this->previewValidator;
    }

    private function coverUpload(): CoverUpload
    {
        if ($this->coverUpload instanceof CoverUpload) {
            return $this->coverUpload;
        }

        $this->coverUpload = new CoverUpload();
        return $this->coverUpload;
    }

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
            throw new \RuntimeException('MediaScribe meta-image runtime is not initialized.');
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

    /**
     * Maps one logical table name to its physical name for the current driver.
     *
     * @param string $table Logical application table name.
     * @return string Physical prefixed table name.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
