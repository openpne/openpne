<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;

/**
 * The viewer-visible diaries immediately older and newer than $diary within its own author's
 * timeline, matching OpenPNE 3 Diary::getPrevious/getNext: same author, adjacent by id, and
 * filtered to the audiences the viewer may see. "older" has the smaller id, "newer" the larger —
 * id order, not created_at, so duplicate timestamps stay stable.
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

        return [
            'older' => $visible()->where('id', '<', $diary->getKey())->orderByDesc('id')->first(),
            'newer' => $visible()->where('id', '>', $diary->getKey())->orderBy('id')->first(),
        ];
    }
}
