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
     * @param string $coverValue Stored local cover-image path.
     * @return string Same-origin public URL, or empty string for blank/invalid values.
     */
    public function publicUrl(string $coverValue): string
    {
        $normalized = trim($coverValue);
        // Blank cover values should not emit placeholder URLs.
        if ($normalized === '') {
            return '';
        }

        // Any URI scheme or protocol-relative authority is legacy/external data
        // and must never become a browser image request.
        if (
            preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $normalized) === 1
            || str_starts_with($normalized, '//')
        ) {
            return '';
        }

        // Rooted local paths are already suitable for same-origin rendering.
        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        // Stored relative paths are promoted to rooted public URLs.
        if (str_contains($normalized, '/')) {
            return '/' . $normalized;
        }

        return '/uploads/user/cover/' . rawurlencode(basename($normalized));
    }
}
