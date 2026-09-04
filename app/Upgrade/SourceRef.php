<?php

namespace App\Upgrade;

/**
 * A placeholder for an OpenPNE 3 table named inside a step's raw SQL, resolved by InsertSelectCompiler
 * to the prefixed / database-qualified name. The compiler cannot tell a table name from an alias
 * inside a hand-written subquery, so every source table a subquery scans must be wrapped in
 * SourceRef::table() to reach a `--source-prefix` / `--source-database` source.
 */
final class SourceRef
{
    /** Matches a SourceRef token; the capture group is the source-table name. */
    public const PATTERN = '/\{\{src:([a-z0-9_]+)\}\}/';

    public static function table(string $name): string
    {
        return '{{src:'.$name.'}}';
    }

    /** @return list<string> the distinct source tables tokenised in a raw SQL fragment. */
    public static function tablesIn(string $sql): array
    {
        preg_match_all(self::PATTERN, $sql, $matches);

        return array_values(array_unique($matches[1]));
    }
}
