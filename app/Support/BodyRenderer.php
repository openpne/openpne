<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** The one format-to-renderer mapping, so the Classic and Modern surfaces cannot diverge. */
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
     * Exactly the URLs render() links, in order, so a card is never fetched for text the reader does
     * not see as a link. Op3 decoration is stripped first so a tag attribute cannot read as a URL.
     *
     * @return list<string>
     */
    public static function urls(?string $text, BodyFormat $format): array
    {
        return match ($format) {
            BodyFormat::Markdown => MarkdownText::urls($text),
            BodyFormat::Plain => BodyText::urls($text),
            BodyFormat::Op3 => BodyText::urls(BodyText::stripDecoration($text)),
        };
    }

    /** `$width` is a display width, not a character count: a fullwidth glyph spends two of it. */
    public static function excerpt(?string $text, BodyFormat $format, int $width = BodyText::EXCERPT_WIDTH): string
    {
        return match ($format) {
            BodyFormat::Markdown => MarkdownText::excerpt($text, $width),
            BodyFormat::Plain, BodyFormat::Op3 => BodyText::excerpt($text, $width),
        };
    }

    /** The whole body as plain text with its line structure kept, for a text/plain context such as mail. */
    public static function plainText(?string $text, BodyFormat $format): string
    {
        return match ($format) {
            BodyFormat::Markdown => MarkdownText::plainText($text),
            BodyFormat::Op3 => BodyText::stripDecoration($text),
            BodyFormat::Plain => (string) $text,
        };
    }
}
