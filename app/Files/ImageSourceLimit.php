<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What a processor will read and decode of a stored image, which an OpenPNE 3 row never had checked
 * at upload. Blank or non-positive is at least the upload cap in bytes and, under GD, the pixels PHP's
 * default 128M memory_limit decodes with OPcache on (the sidecar's budget otherwise); a set value is taken as given.
 */
final class ImageSourceLimit
{
    public const DEFAULT_KILOBYTES = 20480;

    /** 4096 x 3072, a 12 MP phone photo turned upright inside PHP's default 128M (docs/internals/security.md, "Decoding an upload"). */
    public const DEFAULT_GD_PIXELS = 12_600_000;

    /** What a GD canonical costs beyond its pixels at 8 bytes each and its source twice over, with a margin for the rest of the request. */
    private const OVERHEAD_BYTES = 21 * 1024 * 1024;

    private const BYTES_PER_PIXEL = 8;

    /** A request's compiled code, which without OPcache is held in memory_limit rather than shared memory. */
    private const WITHOUT_OPCACHE_BYTES = 48 * 1024 * 1024;

    /** Enough of a file for libmagic to name any raster container, the same window the admin raw route reads. */
    public const SNIFF_BYTES = 4096;

    public static function bytes(): int
    {
        $configured = (int) config('openpne.images.max_source_kilobytes');

        return ($configured > 0 ? $configured : max(self::DEFAULT_KILOBYTES, UploadLimit::kilobytes())) * 1024;
    }

    public static function pixels(): int
    {
        $configured = (int) config('openpne.images.max_source_pixels');
        $side = UploadLimit::dimension();

        return $configured > 0 ? $configured : min(self::DEFAULT_GD_PIXELS, ImageIntake::gdPixelCost($side, $side));
    }

    /** The GD pixel limit a memory_limit of $memoryBytes holds an upload of up to the upload cap within; 0 or less when it holds none. */
    public static function pixelsFor(int $memoryBytes, bool $opcache = true): int
    {
        return intdiv($memoryBytes - 2 * UploadLimit::bytes() - self::overhead($opcache), self::BYTES_PER_PIXEL);
    }

    /** The memory_limit, in whole megabytes, that pixelsFor() answers $pixels or more for. */
    public static function megabytesFor(int $pixels, bool $opcache = true): int
    {
        return (int) ceil(($pixels * self::BYTES_PER_PIXEL + 2 * UploadLimit::bytes() + self::overhead($opcache)) / (1024 * 1024));
    }

    private static function overhead(bool $opcache): int
    {
        return self::OVERHEAD_BYTES + ($opcache ? 0 : self::WITHOUT_OPCACHE_BYTES);
    }

    /**
     * Refuses from the header alone, before any processor allocates: bytes over bytes(), a declared
     * side over the intake's side limit where it has one, or more declared pixels than its pixel limit.
     *
     * @throws ImageProcessingException
     */
    public static function preflight(string $bytes, ImageIntake $intake): void
    {
        $maxBytes = self::bytes();

        if (strlen($bytes) > $maxBytes) {
            throw new ImageTooLargeException(sprintf('The image is %d bytes, over the %d byte source limit.', strlen($bytes), $maxBytes));
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            // Only a container this PHP is known not to read (a HEIC before PHP 8.5) is left to the
            // sidecar's own budget; anything else unmeasured is a refusal, as a 500 there would loop.
            $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($bytes, 0, self::SNIFF_BYTES));

            if (! is_string($sniffed) || ! $intake->readsOnlyOutOfProcess($sniffed)) {
                throw new ImageProcessingException('The image header does not declare a size.');
            }

            return;
        }

        $side = $intake->sideLimit();

        if ($side !== null && ($info[0] > $side || $info[1] > $side)) {
            throw new ImageTooLargeException(sprintf('The image declares %dx%d, over the %d px side limit.', $info[0], $info[1], $side));
        }

        $pixels = $intake->pixelLimit();
        $cost = $intake->pixelCost($info[0], $info[1], $intake->countsTurns() && $info[2] === IMAGETYPE_JPEG && self::turnsAQuarter($bytes));

        if ($pixels < $cost) {
            throw new ImageTooLargeException(sprintf('The image declares %dx%d, counted as %d pixels, over the %d pixel limit.', $info[0], $info[1], $cost, $pixels));
        }
    }

    /** Read as intervention/image reads it for the processor, which turns a JPEG only when ext-exif is loaded. */
    private static function turnsAQuarter(string $bytes): bool
    {
        // Every APP1 EXIF segment opens with this signature; a canonical has none, and is then not copied to a stream.
        if (! function_exists('exif_read_data') || ! str_contains($bytes, "Exif\0\0")) {
            return false;
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        try {
            $exif = @exif_read_data($stream, null, true);
        } catch (\Throwable) {
            $exif = false;
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif) ? ($exif['IFD0']['Orientation'] ?? null) : null;

        return is_numeric($orientation) && in_array((int) $orientation, [5, 6, 7, 8], true);
    }
}
