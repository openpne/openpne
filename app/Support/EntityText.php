<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Renders a plain-text body whose spans carry linked entities — today a timeline post's @mentions —
 * as safe HTML.
 *
 * Architecture mirrors Op3Text: cut the entity ranges out of the raw body first and send only the
 * text between them through BodyText (escape, autolink, nl2br), so no rendering rule is duplicated.
 * An entity's own text is escaped but never autolinked: a display name containing "www." would
 * otherwise nest an anchor inside the entity's own.
 *
 * Ranges are half-open [offset, offset+length) in Unicode code points, ascending and
 * non-overlapping. The write path is what guarantees that shape (App\Features\Timeline\Actions\
 * ResolveMentions), and a post is never edited, so a stored range still describes its body; nothing
 * is re-checked here. A range whose entity is gone is simply not passed in and renders as the plain
 * text it always was.
 */
final class EntityText
{
    /**
     * @param  list<array{offset: int, length: int, kind: string, href: string}>  $entities  kind becomes the anchor's class
     */
    public static function render(?string $text, array $entities): HtmlString
    {
        $text = (string) $text;

        $html = '';
        $cursor = 0;

        foreach ($entities as $entity) {
            $html .= self::text(mb_substr($text, $cursor, $entity['offset'] - $cursor));
            $html .= self::anchor(mb_substr($text, $entity['offset'], $entity['length']), $entity['kind'], $entity['href']);
            $cursor = $entity['offset'] + $entity['length'];
        }

        return new HtmlString($html.self::text(mb_substr($text, $cursor)));
    }

    /** Text outside every entity: escape, autolink, and line-break via the shared plain renderer. */
    private static function text(string $text): string
    {
        return $text === '' ? '' : BodyText::render($text)->toHtml();
    }

    private static function anchor(string $label, string $kind, string $href): string
    {
        return '<a href="'.e($href).'" class="'.e($kind).'">'.e($label).'</a>';
    }
}
