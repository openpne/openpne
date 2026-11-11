<?php

declare(strict_types=1);

namespace App\Support;

final class MarkdownEscape
{
    /** Escapes the punctuation App\Support\MarkdownText would read as emphasis, a link, code or a block starter. */
    public static function plain(string $text): string
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
}
