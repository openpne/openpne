<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Every member-typed search and every name order goes through here, so the comparison is chosen
 * once per engine (docs/internals/text-comparison.md, "Search and name order").
 */
final class SearchCollation
{
    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  string  $pattern  a LIKE pattern the caller built, wildcards included
     * @return TQuery
     */
    public static function whereMatches(EloquentBuilder|QueryBuilder $query, string $column, string $pattern, ?string $escape = null, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $base = self::base($query);
        $subject = $base->getGrammar()->wrap($column);
        $collation = self::collation($base->getConnection());

        [$subject, $term] = match (true) {
            $base->getConnection()->getDriverName() === 'sqlite' => [$subject, '?'],
            $collation !== null => ["LOWER({$subject}) COLLATE {$collation}", 'LOWER(?)'],
            default => ["LOWER({$subject})", 'LOWER(?)'],
        };

        $bindings = $escape === null ? [$pattern] : [$pattern, $escape];

        return $query->whereRaw("{$subject} LIKE {$term}".($escape === null ? '' : ' ESCAPE ?'), $bindings, $boolean);
    }

    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function orWhereMatches(EloquentBuilder|QueryBuilder $query, string $column, string $pattern, ?string $escape = null): EloquentBuilder|QueryBuilder
    {
        return self::whereMatches($query, $column, $pattern, $escape, 'or');
    }

    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  string|EloquentBuilder|QueryBuilder  $name  a column, or a subquery selecting one value per row
     * @return TQuery
     */
    public static function orderBy(EloquentBuilder|QueryBuilder $query, string|EloquentBuilder|QueryBuilder $name, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        $base = self::base($query);
        $connection = $base->getConnection();
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        if (is_string($name)) {
            $subject = $base->getGrammar()->wrap($name);
            $bindings = [];
        } else {
            $subject = '('.$name->toSql().')';
            $bindings = $name->getBindings();
        }

        $collation = $connection->getDriverName() === 'sqlite' ? 'NOCASE' : self::collation($connection);
        $collate = $collation === null ? '' : " COLLATE {$collation}";

        return $query->orderByRaw("{$subject}{$collate} {$direction}", $bindings);
    }

    /** Filament's own searches match through the same collation only when it lowers both sides, as whereMatches() does. */
    public static function forcesCaseFolding(): ?bool
    {
        return self::collation(DB::connection()) !== null ? true : null;
    }

    private static function collation(Connection $connection): ?string
    {
        $collation = $connection->getDriverName() === 'sqlite' ? null : $connection->getConfig('search_collation');

        return is_string($collation) && $collation !== '' ? $collation : null;
    }

    private static function base(EloquentBuilder|QueryBuilder $query): QueryBuilder
    {
        return $query instanceof EloquentBuilder ? $query->getQuery() : $query;
    }
}
