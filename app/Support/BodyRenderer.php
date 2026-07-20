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
            BodyFormat::Markdown => MarkdownText::render($text),
            BodyFormat::Plain => BodyText::render($text),
        };
    }

    /**
     * A feed excerpt. Markdown flattens its rendered HTML to plain text (MarkdownText::excerpt);
     * Plain and Op3 share BodyText::excerpt, which strips <op:*> tags and collapses newlines.
     */
    public static function excerpt(?string $text, BodyFormat $format): string
    {
        return match ($format) {
            BodyFormat::Markdown => MarkdownText::excerpt($text),
            BodyFormat::Plain, BodyFormat::Op3 => BodyText::excerpt($text),
        };
    }
}
