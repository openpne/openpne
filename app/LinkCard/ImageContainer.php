<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Decides whether an image is provably a single still frame, by walking its container structure.
 *
 * The question is deliberately "is this safe to decode?" and not "does this look animated?". Those
 * are not complements. A scan that hunts for evidence of animation answers "no" both when the file
 * is a still image and when the parser gave up — ran past a block limit, met a block it did not
 * recognise, read off the end — and the second case is the one an attacker constructs. So every exit
 * that is not a completed walk proving one frame returns false, and the caller refuses the image.
 *
 * Searching the bytes for a marker string does not work either, in both directions. It misses real
 * animations — a two-frame GIF needs no NETSCAPE loop extension, and an animated WebP can carry a
 * padding chunk that pushes its ANIM header past any fixed window — and it invents them, since a
 * still image's compressed data or metadata may contain the same bytes by chance.
 *
 * So each format is parsed the way its specification defines it: length-prefixed blocks for GIF,
 * length-prefixed chunks for PNG and WebP. That is exact, and it is cheap — no pixel data is
 * touched, only the headers that say how far to skip. This runs before any decode, because deciding
 * to decode in order to count frames is the mistake the check exists to prevent: a decoder allocates
 * a full frame at a time, and an out-of-memory kill cannot be caught.
 *
 * The consequence of failing closed is that an unusual but honest file — a still GIF carrying
 * thousands of comment blocks — is refused. That costs a card an image; the other direction costs a
 * worker.
 */
final class ImageContainer
{
    /**
     * A CPU bound on the walk, not a correctness bound.
     *
     * Every loop advances monotonically through the buffer, so termination comes from the buffer
     * length alone; this only stops a pathological file from spending a long time getting there.
     * Reaching it is treated as "could not prove", never as "still".
     */
    private const MAX_BLOCKS = 4096;

    /** Whether $bytes is provably a single-frame image of type $mime. */
    public static function isSafeStill(string $bytes, string $mime): bool
    {
        return match ($mime) {
            // JPEG has no animation container; there is nothing to prove.
            'image/jpeg' => true,
            'image/gif' => self::gifIsStill($bytes),
            'image/png' => self::pngIsStill($bytes),
            'image/webp' => self::webpIsStill($bytes),
            // A container this class cannot parse is one it cannot vouch for.
            default => false,
        };
    }

    /**
     * A GIF is still when the walk reaches the trailer having seen exactly one Image Descriptor.
     *
     * The frame count is exactly the number of image descriptors — an animation is a sequence of
     * them, whether or not it carries the NETSCAPE looping extension, which is optional and controls
     * repetition rather than declaring animation.
     */
    private static function gifIsStill(string $bytes): bool
    {
        $length = strlen($bytes);

        // Header (6) + Logical Screen Descriptor (7).
        if ($length < 13) {
            return false;
        }

        $packed = ord($bytes[10]);
        $offset = 13;

        // Global Colour Table, when the flag is set: 3 bytes per entry, 2^(n+1) entries.
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (1 << (($packed & 0x07) + 1));
        }

        $frames = 0;

        for ($block = 0; $block < self::MAX_BLOCKS; $block++) {
            if ($offset >= $length) {
                return false; // Ran out before the trailer: malformed.
            }

            $introducer = ord($bytes[$offset]);

            if ($introducer === 0x3B) { // Trailer: the walk completed.
                return $frames === 1;
            }

            if ($introducer === 0x21) { // Extension: label, then sub-blocks.
                $offset = self::skipGifSubBlocks($bytes, $offset + 2);

                if ($offset === null) {
                    return false;
                }

                continue;
            }

            if ($introducer !== 0x2C) {
                return false; // A block this parser does not know.
            }

            if (++$frames > 1) {
                return false;
            }

            // Image Descriptor is 10 bytes; its packed field may add a Local Colour Table.
            if ($offset + 9 >= $length) {
                return false;
            }

            $localPacked = ord($bytes[$offset + 9]);
            $offset += 10;

            if (($localPacked & 0x80) !== 0) {
                $offset += 3 * (1 << (($localPacked & 0x07) + 1));
            }

            $offset = self::skipGifSubBlocks($bytes, $offset + 1); // +1 for LZW minimum code size

            if ($offset === null) {
                return false;
            }
        }

        return false; // Block limit reached without proving anything.
    }

    /**
     * Advance past a GIF data sub-block chain: length-prefixed runs ending with a zero length.
     *
     * Null means the chain did not terminate within the buffer or the block limit — a failure the
     * caller must not read as "nothing found here".
     */
    private static function skipGifSubBlocks(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);

        for ($block = 0; $block < self::MAX_BLOCKS; $block++) {
            if ($offset >= $length) {
                return null;
            }

            $size = ord($bytes[$offset]);

            if ($size === 0) {
                return $offset + 1;
            }

            $offset += $size + 1;
        }

        return null;
    }

    /**
     * A PNG is still when the walk reaches IDAT without having met an animation control chunk.
     *
     * acTL is required to precede IDAT, so arriving at the image data proves there is none. Nothing
     * bounds how much ancillary metadata may come first, which is why this follows chunk lengths
     * rather than reading a fixed prefix — and why running out of budget before IDAT is a failure
     * rather than an all-clear.
     */
    private static function pngIsStill(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 8; // Signature.

        for ($chunk = 0; $chunk < self::MAX_BLOCKS; $chunk++) {
            if ($offset + 8 > $length) {
                return false;
            }

            $size = unpack('N', substr($bytes, $offset, 4))[1] ?? null;
            $type = substr($bytes, $offset + 4, 4);

            if ($size === null || $size < 0) {
                return false;
            }

            if ($type === 'acTL') {
                return false;
            }

            if ($type === 'IDAT') {
                return true;
            }

            if ($type === 'IEND') {
                return false; // No image data at all: not something to store.
            }

            $offset += 12 + $size; // length + type + data + CRC
        }

        return false;
    }

    /**
     * A WebP is still when the walk reaches the end of the RIFF payload having seen no animation.
     *
     * Both signals disqualify it: the animation bit in the extended-format header, and the ANIM /
     * ANMF chunks. A file may carry padding chunks before either, so the walk follows the declared
     * chunk sizes to the end rather than reading a prefix.
     */
    private static function webpIsStill(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 12; // 'RIFF' + size + 'WEBP'

        if ($length < $offset) {
            return false;
        }

        for ($chunk = 0; $chunk < self::MAX_BLOCKS; $chunk++) {
            if ($offset === $length) {
                return true; // Walked the whole payload cleanly.
            }

            if ($offset + 8 > $length) {
                return false;
            }

            $fourcc = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? null;

            if ($size === null || $size < 0) {
                return false;
            }

            if ($fourcc === 'ANIM' || $fourcc === 'ANMF') {
                return false;
            }

            if ($fourcc === 'VP8X') {
                if ($offset + 9 >= $length || (ord($bytes[$offset + 8]) & 0x02) !== 0) {
                    return false;
                }
            }

            // Chunk payloads are padded to an even length.
            $offset += 8 + $size + ($size % 2);
        }

        return false;
    }
}
