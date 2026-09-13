<?php

declare(strict_types=1);

namespace App\Files;

use Intervention\Gif\Decoder as GifDecoder;
use Throwable;

/**
 * For bytes a processor produced, which are whole (a cache-disk canonical is published whole or not at
 * all); bytes from anywhere else are App\LinkCard\ImageContainer's question, answered fail-closed.
 */
final class AnimationProbe
{
    public const DEFAULT_GIF_WALK_KILOBYTES = 8192;

    /** The formats a processor's answer can animate in: JPEG has no frames, and neither processor keeps an APNG's (pinned by the contract test). */
    public static function mayAnimate(string $mime): bool
    {
        return in_array($mime, ['image/gif', 'image/webp'], true);
    }

    /** The GIF walk builds every frame as an object at about three times the bytes, so past this it is not attempted and the answer is null. */
    public static function maxGifWalkBytes(): int
    {
        $configured = (int) config('openpne.images.max_gif_walk_kilobytes');

        return ($configured > 0 ? $configured : self::DEFAULT_GIF_WALK_KILOBYTES) * 1024;
    }

    /** True or false when the container says so, null when it cannot be read that far. */
    public static function of(string $bytes, string $mime): ?bool
    {
        return match ($mime) {
            'image/gif' => self::gif($bytes),
            'image/webp' => self::webp($bytes),
            default => false,
        };
    }

    private static function gif(string $bytes): ?bool
    {
        if (strlen($bytes) > self::maxGifWalkBytes()) {
            return null;
        }

        try {
            return count(GifDecoder::decode($bytes)->frames()) > 1;
        } catch (Throwable) {
            return null;
        }
    }

    /** The extended header, when present, is the first chunk, so its animation flag is byte 20. */
    private static function webp(string $bytes): ?bool
    {
        if (strlen($bytes) < 16 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        if (substr($bytes, 12, 4) !== 'VP8X') {
            return false;
        }

        if (strlen($bytes) < 21) {
            return null;
        }

        return (ord($bytes[20]) & 0x02) !== 0;
    }
}
