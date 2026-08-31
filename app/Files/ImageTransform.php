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
     * Bump when a change alters the bytes a transform produces, so the new code does not
     * go on serving what the old code cached. The cache disk outlives a release — hosting
     * points it outside the release directory — and a variant is only ever regenerated on
     * a miss, so without this a stale thumbnail is permanent. Superseded generations are
     * dropped with the rest of the file's variants when the file is deleted.
     */
    private const GENERATION = 2;

    /**
     * Cache path for $file's bytes under this transform:
     * `{name}/g{N}/{driver}-q{quality}/w{W}_h{H}[_sq].{format}`.
     *
     * The key names everything the bytes depend on. The encoder segment carries the two env knobs
     * that change them without a code change: a different driver or quality is a different variant,
     * not a stale one — for the disk cache and for the ETag derived from this key alike.
     */
    public function cacheKey(string $name, string $format): string
    {
        $suffix = $this->square ? '_sq' : '';
        $encoder = config('openpne.images.driver').'-q'.config('openpne.images.quality');

        return "{$name}/g".self::GENERATION."/{$encoder}/w{$this->width}_h{$this->height}{$suffix}.{$format}";
    }

    /**
     * Validator for a response serving this transform of the file named $name: the cache key, hashed.
     * A browser's copy is revalidated without reading anything, and whatever makes a new disk variant
     * — a generation bump, an encoder change — makes every browser's copy stale with it. Clearing
     * the cache disk alone does not: a code change that alters the bytes has to bump GENERATION.
     */
    public function etag(string $name, string $format): string
    {
        return '"'.hash('sha1', $this->cacheKey($name, $format)).'"';
    }
}
