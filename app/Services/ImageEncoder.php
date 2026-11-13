<?php

namespace App\Services;

use RuntimeException;

/**
 * Turns an image file into a base64 data URL for a vision model, downscaled
 * so its longest side is at most $maxSide. Fewer pixels means fewer image
 * tokens, which is most of a vision model's time.
 */
class ImageEncoder
{
    public static function dataUrl(string $path, int $maxSide): string
    {
        $image = @imagecreatefromstring(file_get_contents($path));

        if ($image === false) {
            throw new RuntimeException("Could not read image: {$path}");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $maxSide / max($width, $height);

        if ($scale < 1) {
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
        }

        ob_start();
        imagejpeg($image, null, 90);

        return 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());
    }
}
