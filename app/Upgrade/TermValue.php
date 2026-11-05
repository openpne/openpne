<?php

namespace App\Upgrade;

/**
 * An OpenPNE 3 term value as TermSettings would save it: PHP trim()'s characters (NUL aside) cut from
 * both ends, and nothing carried when nothing is left (docs/internals/upgrade.md, "Terms"). Regular
 * expressions, because the source collation decides what `<> ''` ignores and TRIM() cuts spaces only.
 */
final class TermValue
{
    // Doubled once more for the SQL string parser, which unescapes `\t` itself and drops the `\` of `\x`; `\z` not `$`, which also matches before a final line terminator.
    private const BLANK = '[ \\\\t\\\\n\\\\r\\\\x{0B}]';

    public static function trimmedExpr(string $column): string
    {
        return sprintf("REGEXP_REPLACE(%s, '^%s+|%s+\\\\z', '')", $column, self::BLANK, self::BLANK);
    }

    /** SQL boolean: the column holds something besides blanks; NULL for a NULL column, which a WHERE drops. */
    public static function carriedExpr(string $column): string
    {
        return sprintf("%s NOT REGEXP '^%s*\\\\z'", $column, self::BLANK);
    }
}
