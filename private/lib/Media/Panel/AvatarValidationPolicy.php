<?php

declare(strict_types=1);

namespace Raven\Lib\Media\Panel;

/**
 * Validates avatar uploads using size, dimensions, and binary MIME checks.
 */
final class AvatarValidationPolicy
{
    /** Default maximum avatar file size in bytes (1 MB). */
    private const DEFAULT_MAX_SIZE = 1048576;

    /** Default maximum width in pixels. */
    private const DEFAULT_MAX_WIDTH = 500;

    /** Default maximum height in pixels. */
    private const DEFAULT_MAX_HEIGHT = 500;

    /** Default allowed extension list used when config keys are missing. */
    private const DEFAULT_ALLOWED_EXTENSIONS = 'gif,jpg,jpeg,png';

    /** Maximum avatar file size in bytes from runtime config. */
    private int $maxSizeBytes;

    /** Maximum avatar width in pixels from runtime config. */
    private int $maxWidth;

    /** Maximum avatar height in pixels from runtime config. */
    private int $maxHeight;

    /** @var array<string, string> Allowed MIME => output extension map. */
    private array $allowedMime;

    /** Human-readable extension label for validation errors. */
    private string $allowedExtensionsLabel;

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
     * @param array<string, mixed> $file
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

