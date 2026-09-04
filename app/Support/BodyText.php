<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Plain-text body as safe HTML (OpenPNE 3 op_url_cmd(nl2br())): bare URLs become links, newlines
 * become <br>. Every span is escaped and the URL match stops at <, > and ", so no markup can leak
 * out of a link.
 */
final class BodyText
{
    /** A bare http(s):// or www. URL, ending before whitespace, a tag/quote char, or trailing punctuation. */
    private const URL = '~(\b(?:https?://|www\.)[^\s<>"]+[^\s<>".,;:!?)\]])~iu';

    /** OpenPNE 3 truncates the visible link text (op_auto_link_text truncate_len). */
    private const VISIBLE_URL_LIMIT = 57;

    /** OpenPNE 3 op_truncate(body, 36, '', 3): three rows of display width 36. */
    public const EXCERPT_WIDTH = 108;

    /** OpenPNE 3 op_decoration is_strip: removes <op:*> rich-text tags in both raw and entity-encoded form. */
    private const DECORATION_TAG = '/(?:&lt;|<)(\/?)(op:\w+)(?:\s+((?:(?!&lt;|<).)*))?(?:&gt;|>)/i';

    /**
     * Matched and prefixed exactly as render() links them, so a card is never fetched for text the
     * reader does not see as a link.
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
     * OpenPNE 3 op_truncate(op_decoration(body, true), 36, '', 3): decoration stripped, newlines
     * collapsed, cut to display width with no ellipsis. The result is unescaped text; the caller escapes it.
     */
    public static function excerpt(?string $text, int $width = self::EXCERPT_WIDTH): string
    {
        return self::truncateToRows(self::stripDecoration($text), $width);
    }

    /** OpenPNE 3 op_truncate($text, 36, '', 3) without the decoration strip, for values that are not stored bodies. */
    public static function truncateToRows(?string $text, int $width = self::EXCERPT_WIDTH): string
    {
        $singleLine = strtr((string) $text, ["\r\n" => ' ', "\r" => ' ', "\n" => ' ']);

        return mb_strimwidth($singleLine, 0, $width, '');
    }

    /** Removes the OpenPNE 3 <op:*> decoration tags, leaving the text between them and its newlines intact. */
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
