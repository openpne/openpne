<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Rewrites an OpenPNE 3 site-policy body (raw HTML printed through nl2br with escaping off) as
 * Markdown that App\Support\MarkdownText renders the same way (docs/internals/upgrade.md, "Site
 * policy bodies"). Deliberate difference: MarkdownText autolinks bare URLs, `www.` hosts and email
 * addresses, which OpenPNE 3 left as text.
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

    /**
     * Not HtmlConverter: it collapses whitespace runs and escapes only `*_[]` and a leading `#`, so a
     * `> quoted` or `- item` line would change meaning.
     */
    private static function escapePlain(string $text): string
    {
        // The backslash goes first so the escapes added below are not re-escaped.
        $text = (string) preg_replace('/([\\\\`*_\[\]~])/u', '\\\\$1', $text);
        // Block starters, which only bite at the head of a line: ATX heading, blockquote, bullet
        // (`*` was already escaped above).
        $text = (string) preg_replace('/^(\h*)([#>+-])/mu', '$1\\\\$2', $text);
        // The backslash goes on the list marker's punctuation, not before the digits: an escape only
        // holds before ASCII punctuation, so `\1.` would render literally.
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
