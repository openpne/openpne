<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

/**
 * The viewer-visible diaries immediately older and newer than $diary within its own author's
 * timeline, matching OpenPNE 3 Diary::getPrevious/getNext: same author, adjacent by id, and
 * filtered to the audiences the viewer may see. "older" has the smaller id, "newer" the larger —
 * id order, not created_at, so duplicate timestamps stay stable.
 */
class AdjacentDiaries
{
    /** @return array{older: ?Diary, newer: ?Diary} */
    public function __invoke(Member $viewer, Diary $diary): array
    {
        $owner = $diary->member;

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return ['older' => null, 'newer' => null];
        }

        $clearance = Visibility::clearanceFor($viewer, $owner);
        $visible = fn () => $owner->diaries()->where('visibility', '<=', $clearance->value);

        return [
            'older' => $visible()->where('id', '<', $diary->getKey())->orderByDesc('id')->first(),
            'newer' => $visible()->where('id', '>', $diary->getKey())->orderBy('id')->first(),
        ];
    }
}
