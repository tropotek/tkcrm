<?php

namespace App\Attachments;

use Throwable;

/**
 * Makes the small copy of an image that the attachment list shows.
 */
final class Thumbnail
{
    /**
     * A JPEG no longer than $longest pixels on its longer side, from the contents of an image
     * file. A smaller image is not enlarged. Gives null when the contents cannot be read as an
     * image, in which case the attachment simply has no thumbnail.
     */
    public static function make(string $contents, int $longest): ?string
    {
        try {
            $image = @imagecreatefromstring($contents);

            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, $longest / max($width, $height));
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            // On white, so a transparent PNG does not turn black as a JPEG.
            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            ob_start();
            imagejpeg($thumbnail, null, 82);

            return ob_get_clean() ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
