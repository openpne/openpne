<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * See docs/internals/text-comparison.md, "Identifiers".
 */
final class IdentifierCollation
{
    public const MYSQL = 'utf8mb4_0900_as_ci';

    public const SQLITE = 'NOCASE';

    /** Null on an engine OpenPNE 4 does not support, whose columns keep the table's own collation. */
    public static function for(Connection $connection): ?string
    {
        return match ($connection->getDriverName()) {
            'mysql' => self::MYSQL,
            'sqlite' => self::SQLITE,
            default => null,
        };
    }

    /** Every form trims what is typed into one of these columns, so a stored value this is true of cannot be matched. */
    public static function hasEdgeWhitespace(string $value): bool
    {
        return Str::trim($value) !== $value;
    }
}
