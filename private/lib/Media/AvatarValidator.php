<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/AvatarValidator.php
 * Security utility for validation and protections.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Lib\Media\AvatarValidationPolicy;

/**
 * Core compatibility adapter for avatar upload validation.
 */
final class AvatarValidator
{
    private AvatarValidationPolicy $policy;

    public function __construct(
        ?int $maxSizeBytes = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?string $allowedExtensionsCsv = null
    ) {
        $this->policy = new AvatarValidationPolicy($maxSizeBytes, $maxWidth, $maxHeight, $allowedExtensionsCsv);
    }

    /**
     * @param array<string, mixed> $file One entry from `$_FILES`.
     *
     * @return array{ok: bool, error: string|null, extension: string|null}
     */
    public function validate(array $file): array
    {
        return $this->policy->validate($file);
    }
}
