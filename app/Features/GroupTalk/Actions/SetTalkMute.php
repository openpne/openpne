<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class SetTalkMute
{
    /**
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, Group $group, bool $muted): void
    {
        $groupId = (int) $group->getKey();
        $memberId = (int) $member->getKey();

        // Checked separately from the update: an update that changes nothing reports zero rows on
        // MySQL, so its return value cannot tell "not a member" from "already in that state".
        if (! TalkReadCursor::exists($groupId, $memberId)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::NotMember);
        }

        DB::table('group_members')
            ->where('group_id', $groupId)
            ->where('member_id', $memberId)
            ->update(['is_talk_muted' => $muted]);
    }
}
