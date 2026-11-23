<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
            // MariaDB lowers by the operand's collation, and the binary one's case table predates the UCA one.
            $base->getConnection()->getDriverName() === 'mariadb' && $collation !== null => ["LOWER({$subject} COLLATE {$collation})", "LOWER(? COLLATE {$collation})"],
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

    /**
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  string  $term  what the reader typed, matched anywhere in the column with its wildcards taken literally
     * @return TQuery
     */
    public static function whereContains(EloquentBuilder|QueryBuilder $query, string $column, string $term, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        return self::whereMatches($query, $column, "%{$escaped}%", '!', $boolean);
    }

    private static function collation(Connection $connection): ?string
    {
        $collation = $connection->getDriverName() === 'sqlite' ? null : $connection->getConfig('comparison_collation');

        return is_string($collation) && $collation !== '' ? $collation : null;
    }

    private static function base(EloquentBuilder|QueryBuilder $query): QueryBuilder
    {
        return $query instanceof EloquentBuilder ? $query->getQuery() : $query;
    }
}
