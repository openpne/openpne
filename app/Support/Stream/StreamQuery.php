<?php

namespace App\Support\Stream;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The keyset comparison is written out rather than as SQL's row constructor, which SQLite does not
 * support (docs/internals/ordering.md, "Keyset and offset").
 */
final class StreamQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     * @return StreamPage<TModel>
     */
    public static function older(Builder|Relation $query, ?StreamCursor $before, int $perPage, string $column = 'created_at'): StreamPage
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;
        $time = $builder->qualifyColumn($column);
        $key = $builder->getModel()->getQualifiedKeyName();

        if ($before !== null) {
            $builder->where(fn (Builder $q) => $q
                ->where($time, '<', $before->at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where($time, '=', $before->at)
                    ->where($key, '<', $before->id)));
        }

        $rows = $builder
            ->reorder()
            ->orderByDesc($time)
            ->orderByDesc($key)
            ->limit($perPage + 1)
            ->get();

        return new StreamPage($rows->take($perPage)->values(), $rows->count() > $perPage, $column);
    }
}
