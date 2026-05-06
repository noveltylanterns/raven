<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/AvatarValidator.php
 * Avatar upload validation policy for size, dimensions, and MIME checks.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Validates avatar and cover uploads against image safety constraints.
 */
final class AvatarValidator
{
    /** Default maximum avatar file size in bytes (1 MB). */
    private const DEFAULT_MAX_SIZE = 1048576;

    /** Default maximum width in pixels. */
    private const DEFAULT_MAX_WIDTH = 500;

    /** Default maximum height in pixels. */
    private const DEFAULT_MAX_HEIGHT = 500;

    /** Default extension allowlist when config keys are missing. */
    private const DEFAULT_ALLOWED_EXTENSIONS = 'gif,jpg,jpeg,png';

    /** Maximum file size in bytes from runtime config. */
    private int $maxSizeBytes;

    /** Maximum width in pixels from runtime config. */
    private int $maxWidth;

    /** Maximum height in pixels from runtime config. */
    private int $maxHeight;

    /** @var array<string, string> Allowed MIME => normalized extension map. */
    private array $allowedMime;

    /** Human-readable extension label for validation errors. */
    private string $allowedExtensionsLabel;

    /**
     * @param int|null $maxSizeBytes Maximum upload size in bytes.
     * @param int|null $maxWidth Maximum image width in pixels.
     * @param int|null $maxHeight Maximum image height in pixels.
     * @param string|null $allowedExtensionsCsv Comma-separated extension allowlist.
     * @return void
     */
    public function __construct(
        ?int $maxSizeBytes = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?string $allowedExtensionsCsv = null
    ) {
        $this->maxSizeBytes = max(0, (int) ($maxSizeBytes ?? self::DEFAULT_MAX_SIZE));
        $this->maxWidth = max(1, (int) ($maxWidth ?? self::DEFAULT_MAX_WIDTH));
        $this->maxHeight = max(1, (int) ($maxHeight ?? self::DEFAULT_MAX_HEIGHT));

        $allowList = (string) ($allowedExtensionsCsv ?? self::DEFAULT_ALLOWED_EXTENSIONS);
        $parsedAllowList = $this->parseAllowedExtensions($allowList);
        $this->allowedMime = $parsedAllowList['mime_map'];
        $this->allowedExtensionsLabel = $parsedAllowList['label'];
    }

    /**
     * Validates one uploaded file payload.
     *
     * @param array<string, mixed> $file One entry from `$_FILES`.
     * @return array{ok: bool, error: string|null, extension: string|null}
     */
    public function validate(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed.', 'extension' => null];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Avatar upload appears empty.', 'extension' => null];
        }

        if ($this->maxSizeBytes > 0 && $size > $this->maxSizeBytes) {
            return ['ok' => false, 'error' => 'Avatar must be <= ' . $this->sizeLabel($this->maxSizeBytes) . '.', 'extension' => null];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Temporary upload file missing.', 'extension' => null];
        }

        $bytes = file_get_contents($tmpPath);
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'Failed to inspect upload bytes.', 'extension' => null];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->buffer($bytes);

        if ($this->allowedMime === []) {
            return ['ok' => false, 'error' => 'Avatar upload types are not configured.', 'extension' => null];
        }

        if (!isset($this->allowedMime[$mime])) {
            return ['ok' => false, 'error' => 'Avatar must be ' . $this->allowedExtensionsLabel . '.', 'extension' => null];
        }

        $imageInfo = getimagesizefromstring($bytes);
        if ($imageInfo === false) {
            return ['ok' => false, 'error' => 'Uploaded file is not a valid image.', 'extension' => null];
        }

        [$width, $height] = $imageInfo;
        if ($width > $this->maxWidth || $height > $this->maxHeight) {
            return ['ok' => false, 'error' => 'Avatar must be <= ' . $this->maxWidth . 'x' . $this->maxHeight . '.', 'extension' => null];
        }

        return ['ok' => true, 'error' => null, 'extension' => $this->allowedMime[$mime]];
    }

    /**
     * Parses an extension allowlist into MIME rules and label text.
     *
     * @param string $csv Raw comma-separated extension list.
     * @return array{mime_map: array<string, string>, label: string}
     */
    private function parseAllowedExtensions(string $csv): array
    {
        $parts = preg_split('/[\s,]+/', strtolower(trim($csv))) ?: [];

        $mimeMap = [];
        $labels = [];

        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                continue;
            }

            if ($token === 'gif') {
                $mimeMap['image/gif'] = 'gif';
                $labels['gif'] = true;
                continue;
            }

            if ($token === 'jpg' || $token === 'jpeg') {
                $mimeMap['image/jpeg'] = 'jpg';
                $labels[$token] = true;
                continue;
            }

            if ($token === 'png') {
                $mimeMap['image/png'] = 'png';
                $labels['png'] = true;
            }
        }

        if ($labels === []) {
            return ['mime_map' => $mimeMap, 'label' => 'gif/jpg/jpeg/png'];
        }

        return ['mime_map' => $mimeMap, 'label' => implode('/', array_keys($labels))];
    }

    /**
     * Formats one byte value into a compact user-facing size label.
     *
     * @param int $bytes File-size ceiling in bytes.
     * @return string Human-readable size label.
     */
    private function sizeLabel(int $bytes): string
    {
        if ($bytes % 1048576 === 0) {
            return (string) ((int) ($bytes / 1048576)) . 'MB';
        }

        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 2, '.', ''), '0'), '.') . 'MB';
        }

        if ($bytes % 1024 === 0) {
            return (string) ((int) ($bytes / 1024)) . 'KB';
        }

        return (string) $bytes . ' bytes';
    }
}
