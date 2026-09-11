<?php

namespace App\Support\Stream;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use InvalidArgumentException;

/**
 * The keyset comparison is `t <= ? AND (t < ? OR id < ?)`: SQLite has no row constructor and turns
 * the OR form into a scan (docs/internals/ordering.md, "Keyset and offset").
 */
final class StreamQuery
{
    /**
     * Consumes the query, whose own clauses must hold no top-level `orWhere`: the order is replaced
     * and the predicate and limit are added. A row whose time column is NULL is left out, having no
     * place in the order.
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

        if ($before === null) {
            $builder->whereNotNull($time);
        } else {
            $builder
                ->where($time, '<=', $before->at)
                ->where(fn (Builder $q) => $q
                    ->where($time, '<', $before->at)
                    ->orWhere($key, '<', $before->id));
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
