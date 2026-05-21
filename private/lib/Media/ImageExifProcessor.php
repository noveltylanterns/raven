<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/ImageExifProcessor.php
 * EXIF-orientation normalizer for image write pipelines.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

use Imagick;

/**
 * Converts EXIF orientation metadata into pixel-space transforms.
 */
final class ImageExifProcessor
{
    /**
     * Applies orientation rotation/flip transforms and resets to top-left.
     *
     * @param Imagick $image Image instance to normalize in place.
     * @return void
     */
    public function autoOrient(Imagick $image): void
    {
        $orientation = $image->getImageOrientation();

        switch ($orientation) {
            case Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage('#000', 90);
                break;
            case Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage('#000', -90);
                break;
            case Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
            default:
                break;
        }

        // Orientation tag must be reset so future processors do not rotate twice.
        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
