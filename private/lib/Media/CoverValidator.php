<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/CoverValidator.php
 * Validation policy wrapper for cover-image uploads.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Validates taxonomy and channel cover-image uploads.
 */
final class CoverValidator
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

        // Cover images can be large editorial assets, so dimensions stay permissive.
        $this->validator = new AvatarValidator($maxBytes, 10000, 10000, $allowed);
    }

    /**
     * Validates one cover-image upload payload.
     *
     * @param array<string, mixed> $upload One upload payload from `$_FILES`.
     * @return array{ok: bool, error: string|null, extension: string|null}
     */
    public function validateUpload(array $upload): array
    {
        return $this->validator->validate($upload);
    }
}
