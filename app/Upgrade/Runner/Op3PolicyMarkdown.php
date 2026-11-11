<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Support\MarkdownEscape;
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

        // Not HtmlConverter for text without markup: it collapses whitespace runs and leaves a leading `>` or `-` unescaped.
        return self::hasMarkup($op3) ? self::fromHtml($op3) : MarkdownEscape::plain($op3);
    }

    /** Whether the value carries HTML the browser would have rendered as markup under OpenPNE 3. */
    private static function hasMarkup(string $text): bool
    {
        return preg_match('~<[a-z!/][^>]*>~i', $text) === 1;
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
