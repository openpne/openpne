<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

/**
 * The viewer-visible diaries immediately before and after $diary within its own author's
 * timeline, matching OpenPNE 3 Diary::getPrevious/getNext: same author, adjacent by id, and
 * filtered to the audiences the viewer may see. "previous" is the older entry (smaller id),
 * "next" the newer (larger id) — id order, not created_at, so duplicate timestamps stay stable.
 */
class AdjacentDiaries
{
    /** @return array{previous: ?Diary, next: ?Diary} */
    public function __invoke(Member $viewer, Diary $diary): array
    {
        $owner = $diary->member;

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return ['previous' => null, 'next' => null];
        }

        $clearance = Visibility::clearanceFor($viewer, $owner);
        $visible = fn () => $owner->diaries()->where('visibility', '<=', $clearance->value);

        return [
            'previous' => $visible()->where('id', '<', $diary->getKey())->orderByDesc('id')->first(),
            'next' => $visible()->where('id', '>', $diary->getKey())->orderBy('id')->first(),
        ];
    }
}
