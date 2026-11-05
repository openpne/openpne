<?php

declare(strict_types=1);

namespace App\Files;

use App\Support\BrandColor;
use App\Support\Initial;

/** See docs/internals/site-icon.md, "The generated icon". */
class GeneratedIcon
{
    private const ICO_HEADER_BYTES = 22;

    public function __construct(private readonly GeneratedIconRaster $raster) {}

    public function color(): string
    {
        return brand_color() ?? BrandColor::DEFAULT;
    }

    /** What a posted raster is checked against: the color and the name it was drawn from. */
    public function version(): string
    {
        return substr(sha1($this->color().'|'.sns_name()), 0, 8);
    }

    /** Changes when the raster lands too, so a URL carrying it is re-fetched by an installed app. */
    public function urlVersion(): string
    {
        $version = $this->version();

        return $this->raster->has($version) ? $version.'-r' : $version;
    }

    public function hasRaster(): bool
    {
        return $this->raster->has($this->version());
    }

    /** Firefox draws an SVG onto a canvas only when its root declares width and height. */
    public function svg(bool $rounded = true): string
    {
        $color = $this->color();
        $initial = self::xmlText(Initial::of(sns_name()));
        $fontSize = mb_strlen($initial) === 1 ? 34 : 28;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64"'.($rounded ? ' rx="12"' : '').' fill="'.$color.'"/>'
            .'<text x="32" y="32" dy=".36em" text-anchor="middle" font-family="system-ui, -apple-system, \'Helvetica Neue\', Arial, sans-serif" font-weight="700" font-size="'.$fontSize.'" fill="'.BrandColor::readableForeground($color).'">'
            .htmlspecialchars($initial, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</text></svg>';
    }

    public function raster(int $size): ?string
    {
        return $this->raster->bytes($this->version(), $size);
    }

    /** The best raster at $size: the drawn one when an administrator's browser has sent it, else the tile. */
    public function png(int $size): string
    {
        return $this->raster($size) ?? $this->tile($size);
    }

    /**
     * A square of the brand color with no letter, built byte by byte: a raster letter needs a font,
     * and neither one nor GD (optional on an imgproxy install) is shipped.
     */
    public function tile(int $size): string
    {
        [$r, $g, $b] = sscanf($this->color(), '#%02x%02x%02x');
        $row = "\0".str_repeat(chr($r).chr($g).chr($b)."\xff", $size);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress(str_repeat($row, $size), 9))
            .self::chunk('IEND', '');
    }

    public function ico(): string
    {
        $png = $this->png(32);

        return pack('vvv', 0, 1, 1)
            .pack('CCCCvvVV', 32, 32, 0, 0, 1, self::bitsPerPixel($png), strlen($png), self::ICO_HEADER_BYTES)
            .$png;
    }

    /** Read off the IHDR, since a browser's PNG encoder may write an opaque image without an alpha channel. */
    private static function bitsPerPixel(string $png): int
    {
        $depth = ord($png[24] ?? "\x08");
        $channels = match (ord($png[25] ?? "\x06")) {
            0 => 1,
            2 => 3,
            3 => 1,
            4 => 2,
            default => 4,
        };

        return $depth * $channels;
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
