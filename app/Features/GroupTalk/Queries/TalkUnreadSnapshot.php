<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Group;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where the talk page's unread boundary stood when it was rendered: how many messages were waiting,
 * and the `(talk_read_at, talk_read_message_id)` tuple they begin after.
 *
 * A *snapshot*, and the page treats it as one. Marking read fires seconds later and the poll keeps
 * arriving, but the divider has to keep pointing at the line the reader opened on — a boundary that
 * followed the live cursor would race away from them as they read.
 *
 * The count is `UnreadTalkScope`, the same predicate the badges use, so the number on the banner and
 * the number that sent the reader here cannot disagree. A non-member holds no membership row and so
 * no boundary: null, not zero.
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
