<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Answers whether an image holds more than one frame, by walking its container structure.
 *
 * Searching the bytes for a marker string does not work, in both directions. It misses real
 * animations — a two-frame GIF needs no NETSCAPE loop extension, and an animated WebP can carry a
 * padding chunk that pushes its ANIM header past any fixed window — and it invents them, since a
 * still image's compressed data or metadata may contain the same bytes by chance.
 *
 * So each format is parsed the way its specification defines it: length-prefixed blocks for GIF,
 * length-prefixed chunks for PNG and WebP. That is exact, and it is cheap — no pixel data is
 * touched, only the headers that say how far to skip. Every walk is bounded by both the buffer
 * length and a block count, so a malformed file cannot spin here.
 *
 * This runs before any decode, because deciding to decode in order to count frames is the mistake
 * the check exists to prevent: a decoder allocates a full frame at a time.
 */
final class ImageContainer
{
    /** No legitimate still image needs anywhere near this many blocks to describe itself. */
    private const MAX_BLOCKS = 4096;

    public static function isAnimated(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/gif' => self::gifFrames($bytes) > 1,
            'image/png' => self::pngIsApng($bytes),
            'image/webp' => self::webpIsAnimated($bytes),
            default => false,
        };
    }

    /**
     * The number of Image Descriptor blocks in a GIF.
     *
     * The frame count is exactly this — an animation is a sequence of image descriptors, whether or
     * not it carries the NETSCAPE looping extension, which is optional and controls repetition
     * rather than declaring animation.
     */
    private static function gifFrames(string $bytes): int
    {
        $length = strlen($bytes);

        // Header (6) + Logical Screen Descriptor (7).
        if ($length < 13) {
            return 0;
        }

        $packed = ord($bytes[10]);
        $offset = 13;

        // Global Colour Table, when the flag is set: 3 bytes per entry, 2^(n+1) entries.
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (1 << (($packed & 0x07) + 1));
        }

        $frames = 0;

        for ($block = 0; $block < self::MAX_BLOCKS && $offset < $length; $block++) {
            $introducer = ord($bytes[$offset]);

            if ($introducer === 0x3B) { // Trailer
                break;
            }

            if ($introducer === 0x21) { // Extension: label, then sub-blocks
                $offset = self::skipGifSubBlocks($bytes, $offset + 2);

                continue;
            }

            if ($introducer !== 0x2C) { // Not a block we know: the file is malformed.
                break;
            }

            $frames++;

            if ($frames > 1) {
                return $frames; // No need to keep walking; the answer cannot change.
            }

            // Image Descriptor is 10 bytes; its packed field may add a Local Colour Table.
            if ($offset + 9 >= $length) {
                break;
            }

            $localPacked = ord($bytes[$offset + 9]);
            $offset += 10;

            if (($localPacked & 0x80) !== 0) {
                $offset += 3 * (1 << (($localPacked & 0x07) + 1));
            }

            $offset = self::skipGifSubBlocks($bytes, $offset + 1); // +1 for LZW minimum code size
        }

        return $frames;
    }

    /** Advance past a GIF data sub-block chain: length-prefixed runs ending with a zero length. */
    private static function skipGifSubBlocks(string $bytes, int $offset): int
    {
        $length = strlen($bytes);

        for ($block = 0; $block < self::MAX_BLOCKS && $offset < $length; $block++) {
            $size = ord($bytes[$offset]);

            if ($size === 0) {
                return $offset + 1;
            }

            $offset += $size + 1;
        }

        return $length;
    }

    /**
     * Whether a PNG is an APNG.
     *
     * The animation control chunk must appear before the first IDAT, but nothing bounds how much
     * metadata may precede it, so this walks the chunk lengths rather than reading a fixed prefix.
     */
    private static function pngIsApng(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 8; // Signature.

        for ($chunk = 0; $chunk < self::MAX_BLOCKS && $offset + 8 <= $length; $chunk++) {
            $size = unpack('N', substr($bytes, $offset, 4))[1] ?? 0;
            $type = substr($bytes, $offset + 4, 4);

            if ($type === 'acTL') {
                return true;
            }

            // acTL is required to precede IDAT, so there is nothing left to find after it starts.
            if ($type === 'IDAT' || $type === 'IEND') {
                return false;
            }

            $offset += 12 + $size; // length + type + data + CRC
        }

        return false;
    }

    /**
     * Whether a WebP is animated.
     *
     * Both signals are checked: the animation bit in the extended-format header, and the ANIM/ANMF
     * chunks themselves. A file may carry padding chunks before either, so the walk follows the
     * declared chunk sizes to the end rather than reading a prefix.
     */
    private static function webpIsAnimated(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 12; // 'RIFF' + size + 'WEBP'

        for ($chunk = 0; $chunk < self::MAX_BLOCKS && $offset + 8 <= $length; $chunk++) {
            $fourcc = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0;

            if ($fourcc === 'ANIM' || $fourcc === 'ANMF') {
                return true;
            }

            if ($fourcc === 'VP8X' && $offset + 9 < $length && (ord($bytes[$offset + 8]) & 0x02) !== 0) {
                return true;
            }

            // Chunk payloads are padded to an even length.
            $offset += 8 + $size + ($size % 2);
        }

        return false;
    }
}
