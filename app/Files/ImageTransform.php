<?php

namespace App\Files;

/**
 * `_sq` center-crops to fill the box exactly, which need not be square despite the OpenPNE 3 token.
 * null from fromGeometry() means malformed or outside the size whitelist, and the caller turns that
 * into a 404 so a request cannot drive arbitrary-size generation.
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

    /**
     * Bump when a change outside the cache key — this code, the image library, GD, Imagick, a codec
     * — alters the bytes a transform produces, since a variant is otherwise only regenerated on a
     * miss and the cache disk outlives a release.
     */
    private const GENERATION = 2;

    /**
     * `openpne.images.exif` is in the key because without ext-exif a rotated photo is not turned
     * upright, which is a different picture rather than a stale one.
     */
    public function cacheKey(string $name, string $format): string
    {
        $suffix = $this->square ? '_sq' : '';
        $encoder = $this->isRaw() ? '' : '/'.config('openpne.images.driver').'-q'.config('openpne.images.quality').(config('openpne.images.exif') ? '' : '-noexif');

        return "{$name}/g".self::GENERATION."{$encoder}/w{$this->width}_h{$this->height}{$suffix}.{$format}";
    }

    /**
     * Validator for a response serving this transform of the file named $name: the cache key, hashed.
     * A browser's copy is revalidated without reading anything, and whatever makes a new disk variant
     * makes every browser's copy stale with it.
     */
    public function etag(string $name, string $format): string
    {
        return '"'.hash('sha1', $this->cacheKey($name, $format)).'"';
    }
}
