<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Clears a member's avatar, returning them to no profile image.
 *
 * Mirrors SetAvatar's ordering: the link row is dropped inside the transaction (so a
 * failure rolls back cleanly), and the File's bytes — irreversible on a disk backend — are
 * purged only after commit. A row lock on the member serializes against a concurrent
 * replace. The row is read through a query (not the cached relation, which may be stale) so
 * its File is never missed. No-op when the member has no avatar.
 */
class RemoveAvatar
{
    public function __invoke(Member $member): void
    {
        $replaced = DB::transaction(function () use ($member) {
            $member->newQuery()->whereKey($member->getKey())->lockForUpdate()->first();

            $replaced = $member->avatar()->with('file')->first();
            $member->avatar()->delete();

            return $replaced;
        });

        $replaced?->file?->delete();
    }
}
