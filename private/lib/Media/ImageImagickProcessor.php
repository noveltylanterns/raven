<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/ImageImagickProcessor.php
 * Shared ImageMagick load/normalize helpers for media upload pipelines.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Imagick;

/**
 * Centralizes common ImageMagick normalization steps used across media writers.
 */
final class ImageImagickProcessor
{
    /**
     * Reads one uploaded image and normalizes it to the first frame.
     *
     * @param string $tmpPath Absolute temporary upload path.
     * @return Imagick Loaded ImageMagick instance at frame index 0.
     */
    public function readFirstFrame(string $tmpPath): Imagick
    {
        $image = new Imagick();
        $image->readImage($tmpPath);
        $image->setIteratorIndex(0);
        return $image;
    }

    /**
     * Applies EXIF orientation, optional metadata stripping, and output format.
     *
     * @param Imagick $image Loaded source image instance.
     * @param string $canonicalExtension Canonical normalized extension (`jpg`, `png`, `gif`).
     * @param bool $stripExif Whether metadata should be removed from output.
     * @param ImageExifProcessor $exif EXIF orientation normalizer.
     * @return void
     */
    public function prepareForWrite(
        Imagick $image,
        string $canonicalExtension,
        bool $stripExif,
        ImageExifProcessor $exif
    ): void {
        $exif->autoOrient($image);

        if ($stripExif) {
            $image->stripImage();
        }

        $image->setImageFormat($canonicalExtension === 'jpg' ? 'jpeg' : $canonicalExtension);
        if ($canonicalExtension === 'jpg') {
            // Keep JPEG quality high while still reducing payload size.
            $image->setImageCompressionQuality(85);
        }
    }
}
