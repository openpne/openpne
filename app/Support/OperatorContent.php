<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one place administrator-written page content turns into output
 * (docs/internals/security.md, "Administrator content").
 */
final class OperatorContent
{
    public static function rawHtml(): bool
    {
        return (bool) config('openpne.security.operator_raw_html');
    }

    /** @return string what the page prints unescaped: the stored HTML in raw mode, sanitized Markdown output otherwise */
    public static function render(?string $raw, ?string $markdown): string
    {
        if (self::rawHtml()) {
            return (string) $raw;
        }

        $html = MarkdownText::renderOperator($markdown)->toHtml();

        return $html === '' ? '' : '<div class="markdownBody">'.$html.'</div>';
    }

    /** The inline content of render() output that is a single paragraph, for a slot that sits inside a `<p>`; null for anything else. */
    public static function inlineParagraph(string $rendered): ?string
    {
        return preg_match('~^<div class="markdownBody"><p>((?:(?!</?p\b).)*)</p>\s*</div>$~s', $rendered, $match) === 1
            ? '<span class="markdownBody">'.$match[1].'</span>'
            : null;
    }
}
