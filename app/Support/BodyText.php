<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Renders a user-entered plain-text body as safe HTML, porting OpenPNE 3's
 * op_url_cmd(nl2br(...)) display: bare URLs become links and newlines become <br>.
 *
 * Security: every span is HTML-escaped before it reaches the output. Non-URL text is escaped
 * then line-broken; a URL's href and visible text are escaped individually, and the URL match
 * itself stops at <, >, or " so no markup can leak out of a link. The OpenPNE 3 rich-text
 * decoration (op_decoration) is not applied — it pairs with the un-ported rich-text editor, so
 * plain-text bodies carry no decoration markup.
 */
final class BodyText
{
    /** A bare http(s):// or www. URL, ending before whitespace, a tag/quote char, or trailing punctuation. */
    private const URL = '~(\b(?:https?://|www\.)[^\s<>"]+[^\s<>".,;:!?)\]])~iu';

    /** OpenPNE 3 truncates the visible link text (op_auto_link_text truncate_len). */
    private const VISIBLE_URL_LIMIT = 57;

    public static function render(?string $text): HtmlString
    {
        $segments = preg_split(self::URL, (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $html = '';
        foreach ($segments as $i => $segment) {
            // preg_split with a captured delimiter puts the URLs at the odd indices.
            $html .= $i % 2 === 1 ? self::link($segment) : nl2br(e($segment));
        }

        return new HtmlString($html);
    }

    private static function link(string $url): string
    {
        $href = str_starts_with(strtolower($url), 'www.') ? 'http://'.$url : $url;
        $visible = Str::limit($url, self::VISIBLE_URL_LIMIT, '...');

        return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer nofollow">'.e($visible).'</a>';
    }
}
