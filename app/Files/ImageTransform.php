<?php

namespace App\Files;

/**
 * `_sq` center-crops to fill the box exactly (not necessarily square), `_a` asks a fit box in
 * `animated_sizes` for every frame (docs/internals/images.md, "Processing"). null from fromGeometry()
 * means malformed or outside the whitelists, which the caller turns into a 404.
 */
final class ImageTransform
{
    public function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly bool $square,
        public readonly bool $animated = false,
    ) {}

    /** The full-size canonical (`w_h`): re-encoded, never resized. */
    public static function raw(): self
    {
        return new self(null, null, false, false);
    }

    public function isRaw(): bool
    {
        return $this->width === null && $this->height === null;
    }

    public static function fromGeometry(string $geometry): ?self
    {
        if (! preg_match('/^w(\d*)_h(\d*)(_sq|_a)?$/', $geometry, $m)) {
            return null;
        }

        $suffix = $m[3] ?? '';
        $width = $m[1] === '' ? null : (int) $m[1];
        $height = $m[2] === '' ? null : (int) $m[2];

        // Original size (`w_h`): allowed bare, since a crop needs a box and its frames are the processor's call.
        if ($width === null && $height === null) {
            return $suffix === '' ? self::raw() : null;
        }

        // A partial size (`w120_h`) is malformed; a full size must be whitelisted.
        if ($width === null || $height === null) {
            return null;
        }

        if (! in_array("{$width}x{$height}", config('openpne.images.allowed_sizes'), true)) {
            return null;
        }

        if ($suffix === '_a' && ! in_array("{$width}x{$height}", config('openpne.images.animated_sizes'), true)) {
            return null;
        }

        return new self($width, $height, $suffix === '_sq', $suffix === '_a');
    }

    /**
     * Bump when a change outside the cache key — this code, the image library, GD, a codec — alters
     * the bytes a transform produces, since a variant is otherwise only regenerated on a miss and
     * the cache disk outlives a release.
     */
    private const GENERATION = 3;

    /**
     * The directory every derived file of $name lives under, so the encoder — `openpne.images.exif`
     * included, because without ext-exif a rotated photo is not turned upright, which is a different
     * picture rather than a stale one — is part of every key at once.
     */
    public static function encoderPrefix(string $name): string
    {
        $encoder = config('openpne.images.processor').'-q'.config('openpne.images.quality').(config('openpne.images.exif') ? '' : '-noexif');

        return "{$name}/g".self::GENERATION."/{$encoder}";
    }

    public function cacheKey(string $name, string $format): string
    {
        $suffix = ($this->square ? '_sq' : '').($this->animated ? '_a' : '');

        return self::encoderPrefix($name)."/w{$this->width}_h{$this->height}{$suffix}.{$format}";
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
