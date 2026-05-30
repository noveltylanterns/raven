<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Qr.php
 * Shared QR-code SVG data-URI renderer for panel and public view payloads.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View;

// Load bacon/bacon-qr-code package handler on first use.
(static function (): void {
    $handler = dirname(__DIR__) . '/Composer/bacon/bacon-qr-code.php';
    // Require package bootstrap only when package handler file exists.
    if (is_file($handler)) {
        require_once $handler;
    }
})();

/**
 * Shared QR-code rendering helpers.
 */
final class Qr
{
    /**
     * Builds one base64 SVG data URI for the provided payload string.
     *
     * @param string $payload Raw QR-code payload string to encode.
     * @param int $size Target square size in pixels for the rendered SVG.
     * @return string SVG data URI when the QR package is available; otherwise an empty string.
     */
    public static function dataUriSvgBase64(string $payload, int $size = 220): string
    {
        // Return empty string when required BaconQrCode classes are unavailable.
        if (
            !class_exists(\BaconQrCode\Writer::class)
            || !class_exists(\BaconQrCode\Renderer\ImageRenderer::class)
            || !class_exists(\BaconQrCode\Renderer\RendererStyle\RendererStyle::class)
            || !class_exists(\BaconQrCode\Renderer\Image\SvgImageBackEnd::class)
        ) {
            return '';
        }

        // Render and encode SVG in one guarded block so runtime errors downgrade cleanly.
        try {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(max(80, $size)),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );
            $writer = new \BaconQrCode\Writer($renderer);
            $svg = $writer->writeString($payload);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        // Any rendering failure yields empty output for caller-side fallback handling.
        } catch (\Throwable) {
            return '';
        }
    }
}
