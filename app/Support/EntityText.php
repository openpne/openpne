<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Ranges are half-open [offset, offset+length) in code points, ascending and non-overlapping; the
 * write path guarantees that shape and nothing is re-checked here. An entity's own text is escaped
 * but never autolinked, or a display name containing `www.` would nest an anchor inside the entity's.
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
