<?php

declare(strict_types=1);

namespace Tests\Support;

/** Real, decodable picture bytes for File rows written straight to storage; a raster route draws a canonical from them. */
final class ImageBytes
{
    public static function png(int $width = 8, int $height = 8): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 30, 144, 255));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
