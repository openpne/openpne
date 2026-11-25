<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What a processor reads, the output format it makes of each source type, whether it writes WebP,
 * and the size it will take from a header (docs/internals/images.md, "Processing").
 */
final class ImageIntake
{
    /** The sidecar's shipped `IMGPROXY_MAX_SRC_RESOLUTION`, in megapixels; the compose file states it and a test pins the two together. */
    public const SIDECAR_MEGAPIXELS = 50;

    private const STILL = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /** Neither has a browser that shows it everywhere, so each is answered in a format that does. */
    private const SIDECAR_ONLY = ['image/heic' => 'jpg', 'image/heif' => 'jpg', 'image/avif' => 'webp'];

    /**
     * @param  array<string, string>  $formats  source type => canonical format
     */
    private function __construct(
        private readonly array $formats,
        private readonly bool $writesWebp,
        private readonly ?int $sideLimit,
        private readonly int $pixelLimit,
        private readonly bool $countsRows,
    ) {}

    /**
     * In-process decoding: the four types libgd reads, under the GD pixel limit. Whether it writes
     * WebP is the host's libgd build, read unless a test says otherwise.
     */
    public static function gd(?bool $writesWebp = null): self
    {
        return new self(self::STILL, $writesWebp ?? function_exists('imagewebp'), UploadLimit::dimension(), ImageSourceLimit::pixels(), true);
    }

    /** Out-of-process decoding: no per-side cap, and the pixel cap is the sidecar's budget unless one is configured. */
    public static function imgproxy(): self
    {
        $configured = (int) config('openpne.images.max_source_pixels');

        return new self(self::STILL + self::SIDECAR_ONLY, true, null, $configured > 0 ? $configured : self::SIDECAR_MEGAPIXELS * 1_000_000, false);
    }

    /** Whether a variant may be asked for as WebP whatever its source is; a processor that cannot write it never offers one. */
    public function writesWebp(): bool
    {
        return $this->writesWebp;
    }

    /** Whether $mime is a picture type only the sidecar reads, wherever this site's processor runs. */
    public static function isSidecarOnly(string $mime): bool
    {
        return isset(self::SIDECAR_ONLY[$mime]);
    }

    /** Whether $mime is read here and only ever out of process, where a header this PHP cannot read is the sidecar's to measure. */
    public function readsOnlyOutOfProcess(string $mime): bool
    {
        return isset($this->formats[$mime]) && isset(self::SIDECAR_ONLY[$mime]);
    }

    /** The canonical's format for a source of $mime, or null where this processor cannot read it. */
    public function canonicalFormat(string $mime): ?string
    {
        return $this->formats[$mime] ?? null;
    }

    /** @return list<string> */
    public function mimes(): array
    {
        return array_keys($this->formats);
    }

    /** The `<input accept>` list: every readable type, with the extensions a picker may match on instead. */
    public function accept(): string
    {
        $extensions = array_map(fn (string $mime): string => '.'.substr($mime, strlen('image/')), array_keys(array_intersect_key($this->formats, self::SIDECAR_ONLY)));

        return implode(',', [...$this->mimes(), ...$extensions]);
    }

    public function sideLimit(): ?int
    {
        return $this->sideLimit;
    }

    public function pixelLimit(): int
    {
        return $this->pixelLimit;
    }

    /**
     * Held against pixelLimit(): in process a row costs its width rounded up to 1024 pixels, as the bundled libgd takes each
     * row from Zend in whole 4 KB pages, and a quarter-turn orientation counts the turned shape too (docs/internals/security.md, "Decoding an upload").
     */
    /** Whether pixelCost() uses a quarter turn, so the EXIF is read only where it is. */
    public function countsTurns(): bool
    {
        return $this->countsRows;
    }

    public function pixelCost(int $width, int $height, bool $quarterTurn): int
    {
        return $this->countsRows ? self::gdPixelCost($width, $height, $quarterTurn) : $width * $height;
    }

    public static function gdPixelCost(int $width, int $height, bool $quarterTurn = false): int
    {
        $rows = fn (int $w, int $h): int => (int) ceil($w / 1024) * 1024 * $h;

        return $quarterTurn ? max($rows($width, $height), $rows($height, $width)) : $rows($width, $height);
    }
}
