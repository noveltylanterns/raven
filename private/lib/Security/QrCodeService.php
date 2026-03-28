<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

// Load bacon/bacon-qr-code package handler on first use.
(static function (): void {
    $handler = dirname(__DIR__) . '/Composer/bacon/bacon-qr-code.php';
    if (is_file($handler)) {
        require_once $handler;
    }
})();

/**
 * Shared QR-code rendering helpers.
 */
final class QrCodeService
{
    public static function dataUriSvgBase64(string $payload, int $size = 220): string
    {
        if (
            !class_exists(\BaconQrCode\Writer::class)
            || !class_exists(\BaconQrCode\Renderer\ImageRenderer::class)
            || !class_exists(\BaconQrCode\Renderer\RendererStyle\RendererStyle::class)
            || !class_exists(\BaconQrCode\Renderer\Image\SvgImageBackEnd::class)
        ) {
            return '';
        }

        try {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(max(80, $size)),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );
            $writer = new \BaconQrCode\Writer($renderer);
            $svg = $writer->writeString($payload);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return '';
        }
    }
}

