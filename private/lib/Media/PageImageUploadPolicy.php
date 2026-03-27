<?php

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Shared upload policy helpers for page gallery images.
 */
final class PageImageUploadPolicy
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<int, string>
     */
    public function allowedExtensions(): array
    {
        $raw = strtolower((string) $this->config->get('media.allowed_extensions', 'gif,jpg,jpeg,png'));
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

    public function maxUploadFilesizeBytes(): int
    {
        $config = $this->config->all();
        $media = $config['media'] ?? null;
        if (is_array($media) && array_key_exists('max_filesize_kb', $media)) {
            $kilobytes = (int) $media['max_filesize_kb'];
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

    public function uploadErrorMessage(int $code): string
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

