<?php

namespace App\Files;

use InvalidArgumentException;

/**
 * What to make of an image: the full-size canonical, a fit inside a box, or a cover of a box.
 */
final class ImageSpec
{
    /** Output format per raster type, the same extension the delivery URL carries. */
    private const FORMATS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly bool $cover,
        public readonly string $format,
        public readonly bool $animated,
        public readonly ?string $background,
    ) {}

    public static function formatFor(string $mime): ?string
    {
        return self::FORMATS[$mime] ?? null;
    }

    /** The full-size re-encode: no resize, animation kept where the processor can. */
    public static function canonical(string $format): self
    {
        return new self(null, null, false, self::validFormat($format), true, null);
    }

    /** Scales inside the box, keeps the aspect ratio, never upscales. */
    public static function fit(int $width, int $height, string $format): self
    {
        return new self($width, $height, false, self::validFormat($format), false, null);
    }

    /** Center-crops to fill the box exactly, upscaling a smaller source. */
    public static function cover(int $width, int $height, string $format): self
    {
        return new self($width, $height, true, self::validFormat($format), false, null);
    }

    /** Flattens transparency onto a hex colour (rrggbb). */
    public function withBackground(string $hex): self
    {
        return new self($this->width, $this->height, $this->cover, $this->format, $this->animated, $hex);
    }

    public function isCanonical(): bool
    {
        return $this->width === null && $this->height === null;
    }

    private static function validFormat(string $format): string
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unsupported image format [{$format}].");
        }

        return $format;
    }
}
