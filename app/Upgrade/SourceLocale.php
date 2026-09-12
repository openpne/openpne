<?php

namespace App\Upgrade;

/**
 * OpenPNE 3 `lang` (ja_JP, en_US, …) folded to the OpenPNE 4 locale slug in SQL, an unrecognised
 * value kept verbatim so it satisfies NOT NULL and stays inert rather than mislabelled. A preflight
 * that groups by locale must fold by this same expression: LIKE runs under the source collation, so
 * a PHP fold would disagree on inputs like `JA_JP`.
 */
final class SourceLocale
{
    public static function foldExpr(string $column = 'lang'): string
    {
        return "CASE WHEN `{$column}` LIKE 'ja%' THEN 'ja' WHEN `{$column}` LIKE 'en%' THEN 'en' ELSE `{$column}` END";
    }
}
