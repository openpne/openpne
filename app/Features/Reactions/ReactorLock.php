<?php

namespace App\Features\Reactions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The reactor's row before the surface's locks: the insert's foreign-key check takes this row
 * shared anyway, and a withdrawal holds it exclusively before it takes the surface's, so reading
 * it first keeps the two on one order (docs/internals/reactions.md, "One write path, one lock per surface").
 */
final class ReactorLock
{
    /** Call inside a transaction; false when the member is gone. */
    public static function hold(Member $member): bool
    {
        return DB::table('members')->where('id', $member->getKey())->sharedLock()->value('id') !== null;
    }
}
