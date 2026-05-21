<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/CoverConfig.php
 * Cover-image public URL resolver for user/profile templates.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Resolves stored user cover-image values to public URLs.
 */
final class CoverConfig
{
    /**
     * Resolves one stored cover-image value to a public URL.
     *
     * @param string $coverValue Stored cover-image path or external URL.
     * @return string Public URL, or empty string when value is blank.
     */
    public function publicUrl(string $coverValue): string
    {
        $normalized = trim($coverValue);
        // Blank cover values should not emit placeholder URLs.
        if ($normalized === '') {
            return '';
        }

        // Already-absolute URLs and rooted paths are returned unchanged.
        if (preg_match('#^https?://#i', $normalized) === 1 || str_starts_with($normalized, '/')) {
            return $normalized;
        }

        // Stored relative paths are promoted to rooted public URLs.
        if (str_contains($normalized, '/')) {
            return '/' . $normalized;
        }

        return '/uploads/user/cover/' . rawurlencode(basename($normalized));
    }
}
