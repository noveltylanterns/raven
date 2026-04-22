<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorMDE.php
 * EasyMDE (Markdown editor) PHP helpers for the panel page body editor.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Provides EasyMDE-specific asset URL helpers for the panel page body editor.
 *
 * Centralises the primary asset URLs and ordered fallback path lists so that
 * the page/edit template receives them from PHP rather than duplicating the
 * paths as hardcoded JS literals. Only wired up in PageController, the one
 * controller that actually serves the Markdown (EasyMDE) editor. No injected
 * dependencies — all methods are stateless.
 */
final class EditorMDE
{
    /**
     * Returns the primary local EasyMDE stylesheet URL.
     *
     * Served from the Nginx /mde/ mapping. No CDN is used.
     *
     * @return string Absolute root-relative URL to the EasyMDE stylesheet.
     */
    public function cssUrl(): string
    {
        return '/mde/easymde.min.css';
    }

    /**
     * Returns the primary local EasyMDE script URL.
     *
     * Served from the Nginx /mde/ mapping. No CDN is used.
     *
     * @return string Absolute root-relative URL to the EasyMDE bundle.
     */
    public function scriptUrl(): string
    {
        return '/mde/easymde.min.js';
    }

    /**
     * Returns the ordered CSS fallback path list for the EasyMDE stylesheet.
     *
     * The JS stylesheet fallback loader (`ensureEasyMdeStylesheetFallback`) iterates
     * these candidates in order and injects any that are not already present in the
     * document. The first path matches the primary `<link>` tag; the remaining paths
     * cover alternative Composer install layouts for resilience.
     *
     * @return array<int, string> Ordered fallback href candidates.
     */
    public function cssFallbackPaths(): array
    {
        return [
            '/mde/easymde.min.css',
            '/mde/lib/easymde.min.css',
            '/mde/dist/easymde.min.css',
            '/composer/tualo/easymde/lib/easymde.min.css',
            '/composer/tualo/easymde/dist/easymde.min.css',
        ];
    }

    /**
     * Returns the ordered JS candidate path list for EasyMDE script loading.
     *
     * The JS script fallback loader (`loadEasyMdeScriptCandidates`) tries each
     * src in sequence until `window.EasyMDE` becomes available. The first path
     * matches the primary `<script>` tag; the remaining paths cover alternative
     * Composer install layouts for resilience.
     *
     * @return array<int, string> Ordered fallback src candidates.
     */
    public function jsFallbackPaths(): array
    {
        return [
            '/mde/easymde.min.js',
            '/mde/lib/easymde.min.js',
            '/mde/dist/easymde.min.js',
            '/composer/tualo/easymde/lib/easymde.min.js',
            '/composer/tualo/easymde/dist/easymde.min.js',
        ];
    }
}
