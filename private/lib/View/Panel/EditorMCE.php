<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorMCE.php
 * TinyMCE-specific PHP helpers for the panel page body editor.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Provides TinyMCE-specific helpers for the panel page body editor.
 *
 * Keeps TinyMCE asset URLs and gallery-item payload building out of
 * the shared Editor class and out of the page/edit template so they
 * are only wired up in PageController, the one controller that
 * actually serves the TinyMCE editor. No injected dependencies —
 * all methods are stateless.
 */
final class EditorMCE
{
    /**
     * Builds the compact gallery-item payload consumed by the TinyMCE custom gallery button.
     *
     * Filters out images that are not in `ready` status or that have been explicitly
     * excluded from gallery inclusion. Each item exposes the four variant URLs used by
     * the gallery picker (`original`, `sm`, `md`, `lg`).
     *
     * @param array<int, array<string, mixed>> $galleryImages Gallery image records from media-read hydration.
     * @return array<int, array{id: int, label: string, alt_text: string, caption: string, variants: array{original: string, sm: string, md: string, lg: string}}> Compact gallery items for JSON encoding.
     */
    public function galleryItems(array $galleryImages): array
    {
        $items = [];

        foreach ($galleryImages as $galleryImage) {
            // Only include images that have finished processing.
            if ((string) ($galleryImage['status'] ?? '') !== 'ready') {
                continue;
            }

            // Respect the per-image gallery exclusion flag when it is explicitly set.
            if (array_key_exists('include_in_gallery', $galleryImage) && empty($galleryImage['include_in_gallery'])) {
                continue;
            }

            $variants = is_array($galleryImage['variants'] ?? null) ? $galleryImage['variants'] : [];

            // Use title_text when set; fall back to the original filename for the picker label.
            $label = (string) (($galleryImage['title_text'] ?? '') !== ''
                ? $galleryImage['title_text']
                : ($galleryImage['original_filename'] ?? 'Image'));

            $items[] = [
                'id'       => (int) ($galleryImage['id'] ?? 0),
                'label'    => $label,
                'alt_text' => (string) ($galleryImage['alt_text'] ?? ''),
                'caption'  => (string) ($galleryImage['caption'] ?? ''),
                'variants' => [
                    'original' => (string) ($galleryImage['url'] ?? ''),
                    'sm'       => (string) (($variants['sm']['url'] ?? '') ?: ''),
                    'md'       => (string) (($variants['md']['url'] ?? '') ?: ''),
                    'lg'       => (string) (($variants['lg']['url'] ?? '') ?: ''),
                ],
            ];
        }

        return $items;
    }

    /**
     * Returns the local TinyMCE script URL.
     *
     * TinyMCE is served from the Nginx /mce/ mapping, which points to the
     * local Composer install path. No CDN is used.
     *
     * @return string Absolute root-relative URL to the TinyMCE bundle.
     */
    public function scriptUrl(): string
    {
        return '/mce/tinymce.min.js';
    }
}
