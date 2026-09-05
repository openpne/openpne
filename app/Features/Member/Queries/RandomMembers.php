<?php

namespace App\Features\Member\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * `is_login_rejected` is deliberately not excluded: it gates logging in and receiving, not being
 * seen, and member search lists such a member too.
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
