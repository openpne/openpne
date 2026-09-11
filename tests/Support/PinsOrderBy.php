<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The `order by` clauses the SQL actually carried: a same-second fixture rarely bites on its own,
 * because an index scan already returns a tie in key order on both engines.
 */
trait PinsOrderBy
{
    /**
     * @param  callable(): mixed  $run
     * @return list<string> one `order by …` clause (quotes stripped, `limit` dropped) per query on $table
     */
    protected function orderClausesOn(string $table, callable $run): array
    {
        DB::enableQueryLog();
        try {
            $run();
            $sql = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }

        return $sql
            ->filter(fn (string $q) => preg_match('/from [`"]'.preg_quote($table, '/').'[`"].* order by/s', $q) === 1)
            ->map(fn (string $q) => preg_replace('/ limit .*$/s', '', preg_replace('/[`"]/', '', substr($q, strrpos($q, 'order by')))))
            ->values()->all();
    }
}
