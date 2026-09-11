<?php

namespace App\Support\Stream;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use InvalidArgumentException;

/**
 * The keyset comparison is written out rather than as SQL's row constructor, which SQLite does not
 * support (docs/internals/ordering.md, "Keyset and offset").
 */
final class StreamQuery
{
    /**
     * Consumes the query: its order is replaced and the predicate and limit stay on it. A row whose
     * time column is NULL is left out, having no place in the order (docs/internals/ordering.md, "Keyset and offset").
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|HasOneOrMany<TModel, *, *>  $query
     * @param  string  $column  unqualified
     * @return StreamPage<TModel>
     */
    public static function older(Builder|HasOneOrMany $query, ?StreamCursor $before, int $perPage, string $column = 'created_at'): StreamPage
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException('A stream page holds at least one row.');
        }

        $builder = $query instanceof HasOneOrMany ? $query->getQuery() : $query;
        $time = $builder->qualifyColumn($column);
        $key = $builder->getModel()->getQualifiedKeyName();

        $builder->whereNotNull($time);
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
