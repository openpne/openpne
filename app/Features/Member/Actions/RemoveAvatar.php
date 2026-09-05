<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The row lock serializes against a concurrent replace. The replaced row is read by query, not
 * through the cached relation, so its File is never missed.
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

        // Bytes are irreversible on a disk backend; purge only now the delete is committed.
        $replaced?->file?->delete();
    }
}
