<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;

/**
 * The viewer-visible diaries either side of $diary in its author's archive, matching OpenPNE 3
 * Diary::getPrevious/getNext: same author, filtered to the audiences the viewer may see. Unlike
 * OpenPNE 3, adjacency follows the archive's own order (created_at, id), so the links walk the list
 * the reader came from (docs/internals/ordering.md, "Prev / next derive from the list").
 */
class AdjacentDiaries
{
    /** @return array{older: ?Diary, newer: ?Diary} */
    public function __invoke(?Member $viewer, Diary $diary): array
    {
        $owner = $diary->member;

        $visible = function () use ($viewer, $owner) {
            $query = Diary::query()->where('member_id', $owner->getKey());
            DiaryVisibilityScope::apply($query, $viewer, $owner);

            return $query;
        };

        $beside = fn (Builder $q, string $op) => $q
            ->where('created_at', $op, $diary->created_at)
            ->orWhere(fn (Builder $tie) => $tie
                ->where('created_at', '=', $diary->created_at)
                ->where('id', $op, $diary->getKey()));

        return [
            'older' => $visible()->where(fn (Builder $q) => $beside($q, '<'))
                ->orderByDesc('created_at')->orderByDesc('id')->first(),
            'newer' => $visible()->where(fn (Builder $q) => $beside($q, '>'))
                ->orderBy('created_at')->orderBy('id')->first(),
        ];
    }
}
