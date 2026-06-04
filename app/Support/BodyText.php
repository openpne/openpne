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

    /** op_truncate(body, 36, '', 3): up to three rows of display width 36 in the OpenPNE 3 table cell. */
    private const EXCERPT_WIDTH = 108;

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

    /**
     * A plain-text feed excerpt, porting OpenPNE 3's op_truncate(op_decoration(body, true), 36, '', 3):
     * newlines collapse to spaces and the text is cut to display width 108 with no ellipsis. The
     * OpenPNE 3 table cell wrapped it into three rows of 36; the Classic feed shows a single line.
     * op_decoration runs with is_strip, which on the plain-text bodies we store is a no-op. Blade
     * escapes the returned string.
     */
    public static function excerpt(?string $text): string
    {
        $singleLine = strtr((string) $text, ["\r\n" => ' ', "\r" => ' ', "\n" => ' ']);

        return mb_strimwidth($singleLine, 0, self::EXCERPT_WIDTH, '');
    }

    private static function link(string $url): string
    {
        $href = str_starts_with(strtolower($url), 'www.') ? 'http://'.$url : $url;
        $visible = Str::limit($url, self::VISIBLE_URL_LIMIT, '...');

        return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer nofollow">'.e($visible).'</a>';
    }
}
