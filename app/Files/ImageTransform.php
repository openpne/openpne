<?php

namespace App\Files;

/**
 * A validated thumbnail transform parsed from an OpenPNE 3-style geometry segment
 * (`w120_h120`, `w_h` for the original size, `w120_h120_sq` for a centre-cropped
 * square). null from fromGeometry() means the request is malformed or asks for a
 * size outside the whitelist — the caller turns that into a 404, so a request cannot
 * drive arbitrary-size generation.
 */
final class ImageTransform
{
    public function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly bool $square,
    ) {}

    public function isRaw(): bool
    {
        return $this->width === null && $this->height === null;
    }

    public static function fromGeometry(string $geometry): ?self
    {
        if (! preg_match('/^w(\d*)_h(\d*)(_sq)?$/', $geometry, $m)) {
            return null;
        }

        $square = ($m[3] ?? '') === '_sq';
        $width = $m[1] === '' ? null : (int) $m[1];
        $height = $m[2] === '' ? null : (int) $m[2];

        // Original size (`w_h`): allowed, but a square crop needs concrete dimensions.
        if ($width === null && $height === null) {
            return $square ? null : new self(null, null, false);
        }

        // A partial size (`w120_h`) is malformed; a full size must be whitelisted.
        if ($width === null || $height === null) {
            return null;
        }

        if (! in_array("{$width}x{$height}", config('openpne.images.allowed_sizes'), true)) {
            return null;
        }

        return new self($width, $height, $square);
    }

    /** Cache path for $file's bytes under this transform: `{name}/w{W}_h{H}[_sq].{format}`. */
    public function cacheKey(string $name, string $format): string
    {
        $suffix = $this->square ? '_sq' : '';

        return "{$name}/w{$this->width}_h{$this->height}{$suffix}.{$format}";
    }
}
