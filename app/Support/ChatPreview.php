<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one line a conversation leads with wherever it is listed — the room list, the conversation
 * list, and the group page's talk card. Shared so the three cannot disagree about how much of a
 * message travels or what a message with nothing but pictures reads as.
 */
final class ChatPreview
{
    /**
     * How much of a body travels. The rows show one line and clip it in CSS, so this is a payload
     * bound, not the visible truncation. Nothing is appended: the ellipsis belongs to the clip.
     */
    private const LIMIT = 140;

    /**
     * The line to lead with: the first of $texts that has anything in it — a body, then a mailbox
     * message's subject — and failing all of them the stand-in for a message that is pictures alone.
     *
     * The emptiness test is strict against `''` and lives here rather than at the three call sites,
     * because PHP's `?:` would read a body of "0" as nothing and drop a message a member did write.
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
