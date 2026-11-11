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

    /** @return string HTML that is safe to print as is */
    public static function render(?string $raw, ?string $markdown): string
    {
        if (self::rawHtml()) {
            return (string) $raw;
        }

        $html = MarkdownText::renderOperator($markdown)->toHtml();

        return $html === '' ? '' : '<div class="markdownBody">'.$html.'</div>';
    }
}
