<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Group;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A snapshot of the boundary at render time: mark-read fires seconds later, and a divider that
 * followed the live cursor would race away from the reader. A non-member holds no membership row and
 * so no boundary: null, not zero.
 */
class TalkUnreadSnapshot
{
    /** @return array{count: int, at: CarbonImmutable, id: int}|null */
    public function __invoke(Group $group, Member $viewer): ?array
    {
        $viewerId = (int) $viewer->getKey();

        $unread = UnreadTalkScope::correlate(
            DB::table('group_messages')->selectRaw('count(*)'),
            'group_members',
            $viewerId,
        );

        $row = DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $viewerId)
            ->select('talk_read_at', 'talk_read_message_id')
            ->selectSub($unread, 'unread')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'count' => (int) $row->unread,
            'at' => CarbonImmutable::parse($row->talk_read_at),
            'id' => (int) $row->talk_read_message_id,
        ];
    }
}
