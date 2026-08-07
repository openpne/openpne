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
     * The URLs this body links to, in the order they appear.
     *
     * Dispatched here for the same reason rendering is: what a link card is fetched for must be
     * exactly what the reader sees as a link, and that differs per format. An op3 body's decoration
     * tags are stripped first so a colour attribute cannot read as a URL.
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

    /**
     * The full body as plain text for a text/plain context (notification mail), so a Markdown body is
     * not emailed as literal `**bold**` and an op3 body carries no `<op:*>` tags. Unlike excerpt() this
     * keeps the whole body and its line structure — no width cut. Plain passes through unchanged.
     */
    public static function plainText(?string $text, BodyFormat $format): string
    {
        return match ($format) {
            BodyFormat::Markdown => MarkdownText::plainText($text),
            BodyFormat::Op3 => BodyText::stripDecoration($text),
            BodyFormat::Plain => (string) $text,
        };
    }
}
