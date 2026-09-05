<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class CountGroupsWithUnreadTalk
{
    public function __invoke(Member $viewer): int
    {
        $viewerId = (int) $viewer->getKey();

        return DB::table('group_members')
            ->where('member_id', $viewerId)
            ->where('is_talk_muted', false)
            ->whereExists(fn ($messages) => UnreadTalkScope::correlate(
                $messages->selectRaw('1')->from('group_messages'),
                'group_members',
                $viewerId,
            ))
            ->count();
    }
}
