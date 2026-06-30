<?php

namespace App\Upgrade;

/**
 * A placeholder for an OpenPNE 3 source table named inside a step's raw SQL (a correlated
 * subquery's FROM), resolved by InsertSelectCompiler to the prefixed / database-qualified name.
 *
 * The compiler can qualify the step's FROM table on its own, but it cannot tell a source-table
 * name apart from an alias or a column inside a hand-written subquery string. Wrapping the source
 * tables a subquery scans in SourceRef::table() makes them explicit, so a `--source-prefix` /
 * `--source-database` reaches them too. The FROM table is aliased to its original name, so a
 * subquery still references the outer row (and its own columns) by that bare name.
 */
final class SourceRef
{
    public static function table(string $name): string
    {
        return '{{src:'.$name.'}}';
    }
}
