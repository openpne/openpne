<?php

declare(strict_types=1);

namespace App\Files;

/**
 * Walks an image container to classify it and measure it, without decoding a pixel.
 *
 * The question is "what did the structure prove?", not "does this look animated?". Those are not
 * complements: a scan hunting for evidence of animation answers "no" both for a still image and for
 * a file the parser gave up on, and the second is the one an attacker constructs. So every exit that
 * is not a completed walk is {@see ImageStructure::Invalid}, and the caller refuses the image.
 *
 * Searching the bytes for a marker string fails in both directions. It misses real animations — a
 * two-frame GIF needs no NETSCAPE extension, an animated WebP can carry padding that pushes ANIM
 * past any fixed window — and it invents them, because compressed data may contain the same bytes by
 * chance. So each format is parsed as its specification defines it, following declared lengths.
 *
 * This runs before any decode. Deciding to decode in order to count frames is the mistake the check
 * exists to prevent: a decoder allocates a full canvas per frame, and an out-of-memory kill cannot
 * be caught.
 *
 * The budgets below bound the walk and what may be handed onward. Reaching one is "could not
 * prove", never "still" — an unusual but honest file is refused, which costs one image; the other
 * direction costs the worker.
 */
final class ImageInspector
{
    /**
     * A CPU bound on the walk, not a correctness bound. Every loop advances monotonically through
     * the buffer, so termination comes from the buffer length; this stops a file made of a great
     * many one-byte blocks from spending a long time getting there.
     */
    private const MAX_BLOCKS = 4096;

    /**
     * Total decoded pixels across every frame (canvas x canvas x frames).
     *
     * 50 megapixels, matching imgproxy's documented default for a source image — which, for an
     * animation, it applies by summing the frames — and sitting inside the band MediaWiki has used
     * for $wgMaxAnimatedGifArea (12.5MP core default, raised to 50MP then 100MP on Commons).
     */
    private const MAX_DECODED_PIXELS = 50_000_000;

    /**
     * A frame ceiling on top of the pixel budget, because a tiny canvas can pass it with tens of
     * thousands of frames and still cost real time in the parser and any later encode. This one is
     * OpenPNE's own budget, not a borrowed default: the tools that publish a number (imgproxy's 1,
     * ImageMagick's list-length example of 32) assume animation is not being preserved at all.
     */
    private const MAX_FRAMES = 200;

    public static function inspect(string $bytes, string $mime): ImageInspection
    {
        $inspection = match ($mime) {
            'image/jpeg' => self::jpeg($bytes),
            'image/gif' => self::gif($bytes),
            'image/png' => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default => ImageInspection::invalid(),
        };

        if (! $inspection->isValid()) {
            return $inspection;
        }

        // Structural ceilings only. A deployment's own bounds — the upload rules' per-side limit,
        // the outbound pixel cap — belong to the caller that has a policy; this class reports the
        // shape so they can be applied without decoding.
        if ($inspection->width < 1 || $inspection->height < 1
            || $inspection->frames > self::MAX_FRAMES
            || $inspection->decodedPixels() > self::MAX_DECODED_PIXELS) {
            return ImageInspection::invalid();
        }

        return $inspection;
    }

    /**
     * JPEG carries no animation container, so the walk proves structure rather than frame count:
     * SOI, a segment chain that reaches the scan, and dimensions from a frame header.
     */
    private static function jpeg(string $bytes): ImageInspection
    {
        $length = strlen($bytes);

        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return ImageInspection::invalid();
        }

        $offset = 2;

        for ($segment = 0; $segment < self::MAX_BLOCKS; $segment++) {
            if ($offset + 4 > $length || $bytes[$offset] !== "\xFF") {
                return ImageInspection::invalid();
            }

            $marker = ord($bytes[$offset + 1]);
            $size = unpack('n', substr($bytes, $offset + 2, 2))[1];

            if ($size < 2 || $offset + 2 + $size > $length) {
                return ImageInspection::invalid();
            }

            // SOF0..SOF15, minus the two that are not frame headers (DHT 0xC4, DAC 0xCC).
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xCC) {
                if ($size < 7) {
                    return ImageInspection::invalid();
                }

                $height = unpack('n', substr($bytes, $offset + 5, 2))[1];
                $width = unpack('n', substr($bytes, $offset + 7, 2))[1];

                return ImageInspection::of(ImageStructure::Still, $width, $height, 1);
            }

            $offset += 2 + $size;
        }

        return ImageInspection::invalid();
    }

    /**
     * The frame count is exactly the number of Image Descriptors — an animation is a sequence of
     * them, whether or not it carries the optional NETSCAPE looping extension. The walk must reach
     * the trailer, and nothing may follow it.
     */
    private static function gif(string $bytes): ImageInspection
    {
        $length = strlen($bytes);

        if ($length < 13 || ! in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return ImageInspection::invalid();
        }

        $width = unpack('v', substr($bytes, 6, 2))[1];
        $height = unpack('v', substr($bytes, 8, 2))[1];
        $packed = ord($bytes[10]);
        $offset = 13;

        if (($packed & 0x80) !== 0) {
            $offset += 3 * (1 << (($packed & 0x07) + 1));
        }

        $frames = 0;

        for ($block = 0; $block < self::MAX_BLOCKS; $block++) {
            if ($offset >= $length) {
                return ImageInspection::invalid();
            }

            $introducer = ord($bytes[$offset]);

            if ($introducer === 0x3B) {
                // Trailing bytes after the trailer are not part of any GIF this app will serve.
                return $frames >= 1 && $offset + 1 === $length
                    ? ImageInspection::of($frames === 1 ? ImageStructure::Still : ImageStructure::Animated, $width, $height, $frames)
                    : ImageInspection::invalid();
            }

            if ($introducer === 0x21) {
                $offset = self::skipGifSubBlocks($bytes, $offset + 2);

                if ($offset === null) {
                    return ImageInspection::invalid();
                }

                continue;
            }

            if ($introducer !== 0x2C || $offset + 10 > $length) {
                return ImageInspection::invalid();
            }

            $frames++;
            $localPacked = ord($bytes[$offset + 9]);
            $offset += 10;

            if (($localPacked & 0x80) !== 0) {
                $offset += 3 * (1 << (($localPacked & 0x07) + 1));
            }

            $offset = self::skipGifSubBlocks($bytes, $offset + 1); // +1 for the LZW minimum code size

            if ($offset === null) {
                return ImageInspection::invalid();
            }
        }

        return ImageInspection::invalid();
    }

    /** Length-prefixed sub-block chain ending with a zero length; null means it did not terminate. */
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
     * IHDR first, IEND last, nothing after it, every CRC correct. An APNG declares its frame count
     * in acTL, which must precede IDAT; the declared count has to match the fcTL chunks actually
     * present, so a header claiming one thing and a body carrying another is refused rather than
     * believed.
     */
    private static function png(string $bytes): ImageInspection
    {
        $length = strlen($bytes);

        if ($length < 8 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return ImageInspection::invalid();
        }

        $offset = 8;
        $width = 0;
        $height = 0;
        $declaredFrames = null;
        $frameControls = 0;
        $sawData = false;

        for ($chunk = 0; $chunk < self::MAX_BLOCKS; $chunk++) {
            if ($offset + 12 > $length) {
                return ImageInspection::invalid();
            }

            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);

            if ($size < 0 || $offset + 12 + $size > $length) {
                return ImageInspection::invalid();
            }

            $crc = unpack('N', substr($bytes, $offset + 8 + $size, 4))[1];

            if ($crc !== crc32(substr($bytes, $offset + 4, 4 + $size))) {
                return ImageInspection::invalid();
            }

            if ($chunk === 0 && $type !== 'IHDR') {
                return ImageInspection::invalid();
            }

            switch ($type) {
                case 'IHDR':
                    if ($size < 8) {
                        return ImageInspection::invalid();
                    }
                    $width = unpack('N', substr($bytes, $offset + 8, 4))[1];
                    $height = unpack('N', substr($bytes, $offset + 12, 4))[1];
                    break;

                case 'acTL':
                    // Declared before the image data, and only once.
                    if ($size < 8 || $sawData || $declaredFrames !== null) {
                        return ImageInspection::invalid();
                    }
                    $declaredFrames = unpack('N', substr($bytes, $offset + 8, 4))[1];
                    break;

                case 'fcTL':
                    $frameControls++;
                    break;

                case 'IDAT':
                    $sawData = true;
                    break;

                case 'IEND':
                    if (! $sawData || $offset + 12 !== $length) {
                        return ImageInspection::invalid();
                    }

                    if ($declaredFrames === null) {
                        return ImageInspection::of(ImageStructure::Still, $width, $height, 1);
                    }

                    return $declaredFrames === $frameControls && $declaredFrames >= 1
                        ? ImageInspection::of(
                            $declaredFrames === 1 ? ImageStructure::Still : ImageStructure::Animated,
                            $width,
                            $height,
                            $declaredFrames,
                        )
                        : ImageInspection::invalid();
            }

            $offset += 12 + $size;
        }

        return ImageInspection::invalid();
    }

    /**
     * The RIFF size must describe the buffer, and the animation bit in VP8X must agree with the
     * chunks actually present: a header that claims animation without ANIM/ANMF, or frames without
     * the bit, is a disagreement this cannot serve either way.
     */
    private static function webp(string $bytes): ImageInspection
    {
        $length = strlen($bytes);

        if ($length < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return ImageInspection::invalid();
        }

        $declared = unpack('V', substr($bytes, 4, 4))[1];

        if ($declared + 8 !== $length) {
            return ImageInspection::invalid();
        }

        $offset = 12;
        $width = 0;
        $height = 0;
        $frames = 0;
        $animationFlag = false;
        $sawAnim = false;

        for ($chunk = 0; $chunk < self::MAX_BLOCKS; $chunk++) {
            if ($offset === $length) {
                if ($animationFlag !== ($sawAnim && $frames >= 1)) {
                    return ImageInspection::invalid();
                }

                return $frames > 1
                    ? ImageInspection::of(ImageStructure::Animated, $width, $height, $frames)
                    : ImageInspection::of(ImageStructure::Still, $width, $height, max($frames, 1));
            }

            if ($offset + 8 > $length) {
                return ImageInspection::invalid();
            }

            $fourcc = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $padded = $size + ($size % 2); // RIFF pads odd-sized payloads to an even boundary.

            if ($size < 0 || $offset + 8 + $padded > $length) {
                return ImageInspection::invalid();
            }

            switch ($fourcc) {
                case 'VP8X':
                    if ($size < 10) {
                        return ImageInspection::invalid();
                    }
                    $animationFlag = (ord($bytes[$offset + 8]) & 0x02) !== 0;
                    // Canvas dimensions are stored minus one, 24 bits each.
                    $width = (ord($bytes[$offset + 12]) | ord($bytes[$offset + 13]) << 8 | ord($bytes[$offset + 14]) << 16) + 1;
                    $height = (ord($bytes[$offset + 15]) | ord($bytes[$offset + 16]) << 8 | ord($bytes[$offset + 17]) << 16) + 1;
                    break;

                case 'ANIM':
                    $sawAnim = true;
                    break;

                case 'ANMF':
                    $frames++;
                    break;

                case 'VP8 ':
                case 'VP8L':
                    // A simple-format file has no VP8X to read the canvas from.
                    if ($width === 0) {
                        [$width, $height] = self::webpBitstreamSize($bytes, $offset, $fourcc, $size);
                        $frames = max($frames, 1);
                    }
                    break;
            }

            $offset += 8 + $padded;
        }

        return ImageInspection::invalid();
    }

    /** @return array{0: int, 1: int} */
    private static function webpBitstreamSize(string $bytes, int $offset, string $fourcc, int $size): array
    {
        if ($fourcc === 'VP8L' && $size >= 5) {
            $bits = unpack('V', substr($bytes, $offset + 9, 4))[1];

            return [($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1];
        }

        if ($fourcc === 'VP8 ' && $size >= 10) {
            return [
                unpack('v', substr($bytes, $offset + 14, 2))[1] & 0x3FFF,
                unpack('v', substr($bytes, $offset + 16, 2))[1] & 0x3FFF,
            ];
        }

        return [0, 0];
    }
}
