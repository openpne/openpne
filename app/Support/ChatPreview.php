<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Shared by every list that previews a message, so truncation and the pictures-only label cannot diverge. */
final class ChatPreview
{
    /** A payload bound, not the visible truncation: the row clips in CSS and adds the ellipsis. */
    private const LIMIT = 140;

    /**
     * The emptiness test is strict against `''`: PHP's `?:` would read a body of "0" as nothing and
     * drop a message a member did write.
     *
     * @param  list<string>  $texts  in the order they should be tried
     */
    public static function lineOrImages(array $texts, bool $hasImages): string
    {
        foreach ($texts as $text) {
            $line = self::line($text);
            if ($line !== '') {
                return $line;
            }
        }

        return self::imagesLine($hasImages);
    }

    /**
     * What a message with no words reads as. Takes whether there are pictures rather than how many:
     * the stand-in never counts them, so a row that lost its attachments falls back to the empty
     * line the caller started with.
     */
    public static function imagesLine(bool $hasImages): string
    {
        return $hasImages ? __('Image') : '';
    }

    /** One line: every run of whitespace becomes a single space, so a multi-line body cannot grow the row. */
    private static function line(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), self::LIMIT, '');
    }
}
