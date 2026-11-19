<?php

namespace App\Filament\Concerns;

use App\Filament\Support\CollatedSearch;
use Illuminate\Database\Eloquent\Builder;

trait CollatedGlobalSearch
{
    /**
     * @param  Builder<*>  $query
     * @param  array<string>  $searchAttributes
     * @return Builder<*>
     */
    protected static function applyGlobalSearchAttributeConstraint(Builder $query, string $search, array $searchAttributes, bool &$isFirst): Builder
    {
        foreach ($searchAttributes as $attribute) {
            CollatedSearch::apply($query, $attribute, $search, $isFirst ? 'and' : 'or');
            $isFirst = false;
        }

        return $query;
    }
}
