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

    /** One line: every run of whitespace becomes a single space, so a multi-line body cannot grow the row. */
    public static function line(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), self::LIMIT, '');
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
}
