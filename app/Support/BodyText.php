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

    /**
     * op_truncate(body, 36, '', 3): up to three rows of display width 36 in the OpenPNE 3 table cell.
     * Public so MarkdownText::excerpt cuts to the same width without duplicating the literal.
     */
    public const EXCERPT_WIDTH = 108;

    /** OpenPNE 3 op_decoration is_strip: removes <op:*> rich-text tags in both raw and entity-encoded form. */
    private const DECORATION_TAG = '/(?:&lt;|<)(\/?)(op:\w+)(?:\s+((?:(?!&lt;|<).)*))?(?:&gt;|>)/i';

    /**
     * The bare URLs this body links to, in the order they appear.
     *
     * Matched by the same expression render() links on, and prefixed the same way, so what a link
     * card is fetched for is exactly what the reader sees underlined. Diverging here would produce
     * the confusing case of a linked URL with no card, or a card for a URL that is not a link.
     *
     * @return list<string>
     */
    public static function urls(?string $text): array
    {
        if (preg_match_all(self::URL, (string) $text, $matches) === false) {
            return [];
        }

        return array_map(self::absolute(...), $matches[1]);
    }

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
     * op_decoration's is_strip removes <op:*> rich-text tags (kept in migrated legacy bodies), newlines
     * collapse to spaces, and the text is cut to display width 108 with no ellipsis. The OpenPNE 3 table
     * cell wrapped it into three rows of 36; the Classic feed shows a single line. Blade escapes the
     * returned string. (The show page renders the full body, which carries the un-ported decoration as a
     * separate Partial; here the tags are only stripped, exactly as OpenPNE 3's excerpt does.)
     *
     * A caller that reads at another size passes its own `$width`; the default is the ported one.
     */
    public static function excerpt(?string $text, int $width = self::EXCERPT_WIDTH): string
    {
        return self::truncateToRows(self::stripDecoration($text), $width);
    }

    /**
     * op_truncate($text, 36, '', 3) without the decoration strip: newlines collapse to spaces and the
     * text is cut to display width 108, no ellipsis. <x-classic.search-result-list> applies it to every
     * caption/value row after the first, as the OpenPNE 3 partial does, over values that are not stored
     * bodies (a self-introduction, a community description).
     */
    public static function truncateToRows(?string $text, int $width = self::EXCERPT_WIDTH): string
    {
        $singleLine = strtr((string) $text, ["\r\n" => ' ', "\r" => ' ', "\n" => ' ']);

        return mb_strimwidth($singleLine, 0, $width, '');
    }

    /**
     * Removes the OpenPNE 3 <op:*> rich-text decoration tags, leaving the text between them (and its
     * newlines) intact. The flatten path (BodyRenderer::plainText, for text/plain mail) reuses this
     * so the op-tag regex lives in one place.
     */
    public static function stripDecoration(?string $text): string
    {
        return preg_replace(self::DECORATION_TAG, '', (string) $text) ?? (string) $text;
    }

    private static function link(string $url): string
    {
        $visible = Str::limit($url, self::VISIBLE_URL_LIMIT, '...');

        return '<a href="'.e(self::absolute($url)).'" target="_blank" rel="noopener noreferrer nofollow">'.e($visible).'</a>';
    }

    /** A matched URL as an absolute one: a bare `www.` host is http, as OpenPNE 3 assumed. */
    private static function absolute(string $url): string
    {
        return str_starts_with(strtolower($url), 'www.') ? 'http://'.$url : $url;
    }
}
