<?php

namespace App\Files;

/**
 * A validated thumbnail transform parsed from an OpenPNE 3-style geometry segment
 * (`w120_h120`, `w_h` for the original size, `w120_h120_sq` to center-crop and fill the
 * box exactly — OpenPNE 3 only ever asked for a square one, hence the token, but the box
 * may be any ratio). null from fromGeometry() means the request is malformed or asks for a
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

    /**
     * Bump when a change alters the bytes a transform produces — this code, a dependency (the image
     * library, GD, Imagick, their codecs) — so the new code does not go on serving what the old code
     * cached. The cache disk outlives a release — hosting points it outside the release directory —
     * and a variant is only ever regenerated on a miss, so without this a stale thumbnail is
     * permanent; and since the ETag derives from the key, clearing the cache disk does not reach
     * browsers either. Superseded generations are dropped with the rest of the file's variants when
     * the file is deleted.
     */
    private const GENERATION = 2;

    /**
     * Cache path for $file's bytes under this transform:
     * `{name}/g{N}/{driver}-q{quality}[-noexif]/w{W}_h{H}[_sq].{format}` — the original (`w_h`)
     * is passed through unencoded, so its key carries no encoder segment.
     *
     * The encoder segment carries what changes the bytes without a code change: the two env knobs,
     * and whether ext-exif is present (without it a rotated photo is not turned upright). Each is a
     * different variant, not a stale one — for the disk cache and for the ETag derived from this key
     * alike. What it does not carry is library and host versions; those are GENERATION's.
     */
    public function cacheKey(string $name, string $format): string
    {
        $suffix = $this->square ? '_sq' : '';
        $encoder = $this->isRaw() ? '' : '/'.config('openpne.images.driver').'-q'.config('openpne.images.quality').(extension_loaded('exif') ? '' : '-noexif');

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
