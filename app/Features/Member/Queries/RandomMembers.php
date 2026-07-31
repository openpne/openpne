<?php

namespace App\Features\Member\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * A random handful of members, for the right rail's faces grid while `friend` is switched off
 * (docs/internals/feature-toggles.md). RandomFriends' unit-independent counterpart: same shape,
 * same cap, the whole SNS as its pool.
 *
 * The viewer is dropped (the grid shows other people) and owners blocking the viewer are excluded,
 * the visibility policy SearchMembers already applies. `is_login_rejected` is deliberately not
 * excluded: it gates logging in and receiving, not being seen, and member search lists such a
 * member too.
 */
class RandomMembers
{
    public const LIMIT = 9;

    /** @return Collection<int, Member> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        $query = Member::query()
            ->whereKeyNot($viewer->getKey())
            ->with('avatar.file');

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'members.id');

        return $query->inRandomOrder()->limit($limit)->get();
    }
}
