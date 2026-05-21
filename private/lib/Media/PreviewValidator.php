<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/PreviewValidator.php
 * Validation policy wrapper for preview/icon image uploads.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Validates taxonomy preview/icon uploads.
 */
final class PreviewValidator
{
    private AvatarValidator $validator;

    /**
     * @param Config $config Runtime config reader.
     * @return void
     */
    public function __construct(Config $config)
    {
        $maxKb = (int) $config->get('media.max_filesize_kb', 10240);
        $maxBytes = $maxKb <= 0 ? 0 : max(1, $maxKb * 1024);
        $allowed = (string) $config->get('media.allowed_extensions', 'gif,jpg,jpeg,png');

        $this->validator = new AvatarValidator($maxBytes, 10000, 10000, $allowed);
    }

    /**
     * Validates one preview/icon upload payload.
     *
     * @param array<string, mixed> $upload One upload payload from `$_FILES`.
     * @return array{ok: bool, error: string|null, extension: string|null}
     */
    public function validateUpload(array $upload): array
    {
        return $this->validator->validate($upload);
    }
}
