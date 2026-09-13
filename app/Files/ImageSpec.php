<?php

namespace App\Files;

use InvalidArgumentException;

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
        public readonly ?string $background,
        public readonly bool $animated = false,
    ) {}

    public static function formatFor(string $mime): ?string
    {
        return self::FORMATS[$mime] ?? null;
    }

    public static function mimeFor(string $format): string
    {
        return (string) array_search(self::validFormat($format), self::FORMATS, true);
    }

    public static function canonical(string $format): self
    {
        return new self(null, null, false, self::validFormat($format), null);
    }

    public static function fit(int $width, int $height, string $format): self
    {
        return new self($width, $height, false, self::validFormat($format), null);
    }

    public static function cover(int $width, int $height, string $format): self
    {
        return new self($width, $height, true, self::validFormat($format), null);
    }

    /** $hex is rrggbb without a leading #. */
    public function withBackground(string $hex): self
    {
        return new self($this->width, $this->height, $this->cover, $this->format, $hex, $this->animated);
    }

    /** Asks for every frame of a fit; a processor that keeps none answers a still, and a canonical asks without this. */
    public function animated(): self
    {
        if ($this->cover) {
            throw new InvalidArgumentException('An animated variant is a fit, never a cover: a cover upscales every frame.');
        }

        return new self($this->width, $this->height, $this->cover, $this->format, $this->background, true);
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
