<?php

namespace App\Features\Block\Actions;

use App\Features\Block\Exceptions\BlockActionException;
use App\Features\Block\Exceptions\BlockActionFailure;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class UnblockMember
{
    public function __invoke(Member $blocker, Member $target): void
    {
        if ($blocker->is($target)) {
            throw new BlockActionException(BlockActionFailure::NotBlocked);
        }

        DB::transaction(function () use ($blocker, $target) {
            $deleted = DB::table('member_blocks')
                ->where('blocker_id', $blocker->getKey())
                ->where('blocked_id', $target->getKey())
                ->delete();

            if ($deleted !== 1) {
                throw new BlockActionException(BlockActionFailure::NotBlocked);
            }
        });
    }
}
