<?php

namespace App\Filament\Support;

use App\Support\Database\SearchCollation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Filament still splits the words and ORs the columns; only the comparison of one word with one
 * attribute is SearchCollation's (docs/internals/text-comparison.md, "Filament").
 */
final class CollatedSearch
{
    /** @return Closure(Builder<*>, string): Builder<*> a column's searchable(query:) */
    public static function on(string $attribute): Closure
    {
        return fn (Builder $query, string $search): Builder => self::apply($query, $attribute, $search);
    }

    /**
     * @param  Builder<*>  $query
     * @param  string  $attribute  a column, or relationship.column to search through the relationship
     * @return Builder<*>
     */
    public static function apply(Builder $query, string $attribute, string $search, string $boolean = 'and'): Builder
    {
        if (! str_contains($attribute, '.')) {
            return SearchCollation::whereContains($query, $query->qualifyColumn($attribute), $search, $boolean);
        }

        return $query->has(
            Str::beforeLast($attribute, '.'),
            '>=',
            1,
            $boolean,
            fn (Builder $related): Builder => SearchCollation::whereContains($related, $related->qualifyColumn(Str::afterLast($attribute, '.')), $search),
        );
    }
}
