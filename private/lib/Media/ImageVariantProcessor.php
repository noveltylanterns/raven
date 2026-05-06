<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/ImageVariantProcessor.php
 * Shared variant-dimension resolver for page and taxonomy image pipelines.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Raven\Core\Config;

/**
 * Shared page-image variant sizing helpers.
 */
final class ImageVariantProcessor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<string, array{width: int, height: int}>
     */
    public function variantSpecs(): array
    {
        return [
            // `0` means "auto" for this axis (aspect-ratio-preserving contain).
            'sm' => [
                'width' => max(0, (int) $this->config->get('media.thumb.sm_x', 200)),
                'height' => max(0, (int) $this->config->get('media.thumb.sm_y', 200)),
            ],
            'md' => [
                'width' => max(0, (int) $this->config->get('media.thumb.md_x', 600)),
                'height' => max(0, (int) $this->config->get('media.thumb.md_y', 600)),
            ],
            'lg' => [
                'width' => max(0, (int) $this->config->get('media.thumb.lg_x', 1000)),
                'height' => max(0, (int) $this->config->get('media.thumb.lg_y', 1000)),
            ],
        ];
    }

    /**
     * @return array{width: int, height: int}
     */
    public function resolveVariantSize(
        int $sourceWidth,
        int $sourceHeight,
        int $maxWidth,
        int $maxHeight
    ): array {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return ['width' => 1, 'height' => 1];
        }

        if ($maxWidth <= 0 && $maxHeight <= 0) {
            return ['width' => $sourceWidth, 'height' => $sourceHeight];
        }

        if ($maxWidth <= 0) {
            $scale = min(1.0, $maxHeight / $sourceHeight);
        } elseif ($maxHeight <= 0) {
            $scale = min(1.0, $maxWidth / $sourceWidth);
        } else {
            $scale = min(1.0, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        }

        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        // Clamp to positive configured max bounds when set.
        if ($maxWidth > 0) {
            $targetWidth = min($targetWidth, $maxWidth);
        }
        if ($maxHeight > 0) {
            $targetHeight = min($targetHeight, $maxHeight);
        }

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

}
