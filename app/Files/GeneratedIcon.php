<?php

declare(strict_types=1);

namespace App\Files;

use App\Support\BrandColor;
use App\Support\Initial;

/**
 * The raster forms are built byte by byte, not through GD, which is optional on an imgproxy install
 * (docs/internals/site-icon.md, "The generated icon").
 */
class GeneratedIcon
{
    private const ICO_HEADER_BYTES = 22;

    public function color(): string
    {
        return brand_color() ?? BrandColor::DEFAULT;
    }

    /** Changes with the color or the name, so a URL carrying it is re-fetched by an installed app. */
    public function version(): string
    {
        return substr(sha1($this->color().'|'.sns_name()), 0, 8);
    }

    public function svg(): string
    {
        $color = $this->color();
        $initial = Initial::of(sns_name());
        $fontSize = mb_strlen($initial) === 1 ? 34 : 28;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="12" fill="'.$color.'"/>'
            .'<text x="32" y="32" dy=".36em" text-anchor="middle" font-family="system-ui, sans-serif" font-weight="700" font-size="'.$fontSize.'" fill="'.BrandColor::readableForeground($color).'">'
            .htmlspecialchars(self::xmlText($initial), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</text></svg>';
    }

    /** A square of the brand color, with no letter: a raster letter needs a font, and none is shipped. */
    public function png(int $size): string
    {
        [$r, $g, $b] = sscanf($this->color(), '#%02x%02x%02x');
        $row = "\0".str_repeat(chr($r).chr($g).chr($b)."\xff", $size);

        // Opaque RGBA (color type 6): the 32 bits per pixel the ICO entry declares.
        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress(str_repeat($row, $size), 9))
            .self::chunk('IEND', '');
    }

    public function ico(): string
    {
        $png = $this->png(32);

        return pack('vvv', 0, 1, 1)
            .pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), self::ICO_HEADER_BYTES)
            .$png;
    }

    /** Control characters have no place in XML text, however the name was saved. */
    private static function xmlText(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
