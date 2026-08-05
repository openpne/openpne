<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Rewrites an OpenPNE 3 site-policy body (sns_config user_agreement / privacy_policy) as Markdown,
 * so it reads on OpenPNE 4 the way it read on OpenPNE 3.
 *
 * OpenPNE 3 printed the stored value as `nl2br($op_config[…])` with output escaping switched off
 * (`sfOutputEscaper::markClassAsSafe('opConfig')`) — the value was raw HTML with newlines honoured.
 * App\Support\MarkdownText escapes raw HTML and reads Markdown punctuation, so a verbatim copy would
 * show an operator's `<h2>` as literal text and turn a line starting with `#` into a heading. Hence
 * two paths, both aiming at "renders the same as OpenPNE 3 did":
 *
 *   - no tags (the common case): escape the Markdown constructs so every character stays literal.
 *     Newlines are left alone — MarkdownText renders a soft break as `<br>`, which is OpenPNE 3's
 *     nl2br. Deliberately not HtmlConverter: it collapses whitespace runs inside a text node and
 *     escapes only `*_[]` and a leading `#`, so `> quoted` and `- item` lines would change meaning.
 *   - tags present: nl2br first (reproducing what OpenPNE 3 emitted), then convert that HTML to
 *     Markdown. Markup with no Markdown equivalent is stripped rather than kept, matching what the
 *     OpenPNE 4 sanitizer would drop from the rendered output anyway.
 *
 * One deliberate difference from OpenPNE 3: MarkdownText autolinks, so a bare URL, a `www.` host and
 * an email address come out as links where OpenPNE 3 left them as text. Suppressing that would mean
 * escaping the colon, the dots and the `@` in the stored body — an operator editing the text later
 * would meet `https\://…` — and every other body in OpenPNE 4 links its URLs.
 */
final class Op3PolicyMarkdown
{
    public static function convert(string $op3): string
    {
        if (trim($op3) === '') {
            return $op3;
        }

        return self::hasMarkup($op3) ? self::fromHtml($op3) : self::escapePlain($op3);
    }

    /** Whether the value carries HTML the browser would have rendered as markup under OpenPNE 3. */
    private static function hasMarkup(string $text): bool
    {
        return preg_match('~<[a-z!/][^>]*>~i', $text) === 1;
    }

    private static function escapePlain(string $text): string
    {
        // Inline constructs. The backslash goes first so the escapes added here are not re-escaped.
        $text = (string) preg_replace('/([\\\\`*_\[\]~])/u', '\\\\$1', $text);
        // Block starters, which only bite at the head of a line: ATX heading, blockquote, bullet
        // (`*` was already escaped above).
        $text = (string) preg_replace('/^(\h*)([#>+-])/mu', '$1\\\\$2', $text);
        // An ordered list marker. The backslash goes on the punctuation, not in front of the digits:
        // a backslash escape only holds before ASCII punctuation, so `\1.` would render literally.
        $text = (string) preg_replace('/^(\h*\d+)([.)])/mu', '$1\\\\$2', $text);

        // A line of `=` alone turns the line above it into a setext heading.
        return (string) preg_replace('/^(\h*)(=+\h*)$/mu', '$1\\\\$2', $text);
    }

    private static function fromHtml(string $html): string
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style',
            // `<br>` becomes a bare newline, which MarkdownText renders back as `<br>`; the default
            // two-space hard break would survive as trailing whitespace nobody can see or edit.
            'hard_break' => true,
            'header_style' => 'atx',
        ]);

        $markdown = $converter->convert(nl2br($html, false));

        // The converter pads block boundaries with several blank lines; collapse them so the stored
        // body reads like something a person wrote (the rendering is the same either way).
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }
}
