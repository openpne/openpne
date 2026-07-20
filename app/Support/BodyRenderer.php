<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Single dispatch from a stored body + its BodyFormat to safe HTML, used by both member surfaces
 * (Classic <x-user-text> and the Modern serializers' bodyHtml). Keeps the format→renderer mapping
 * in one place so the two surfaces cannot diverge.
 */
final class BodyRenderer
{
    public static function render(?string $text, BodyFormat $format): HtmlString
    {
        return match ($format) {
            BodyFormat::Op3 => Op3Text::render($text),
            // Markdown renderer lands in a follow-up PR; fall back to the plain path until then.
            BodyFormat::Plain, BodyFormat::Markdown => BodyText::render($text),
        };
    }

    /**
     * A feed excerpt. Format-independent for now — BodyText::excerpt already strips <op:*> tags and
     * would strip markdown syntax verbatim; a format-aware excerpt lands with the markdown renderer.
     */
    public static function excerpt(?string $text, BodyFormat $format): string
    {
        return BodyText::excerpt($text);
    }
}
