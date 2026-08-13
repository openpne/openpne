<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\TalkRoom;
use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The viewer's own groups read as conversations: most recently talked in first, each carrying the
 * line it leads with and the viewer's unread.
 *
 * **The order is decided in SQL, before the page is cut.** Paging the groups first and then asking
 * for their newest messages would sort the page rather than the membership — a group talked in an
 * hour ago belongs at the top whether or not it falls in the first twenty by id.
 *
 * So the newest message's `(created_at, id)` rides along as two correlated subselects, one per
 * column: the tuple cannot be read in one statement without a row constructor or a lateral join,
 * and neither is portable to SQLite. Each is a single seek on `(group_id, created_at, id)`.
 */
class JoinedTalkRooms
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, TalkRoom> */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $viewerId = (int) $viewer->getKey();

        $page = Group::query()
            ->join('group_members', 'group_members.group_id', '=', 'groups.id')
            ->where('group_members.member_id', $viewerId)
            ->select('groups.*', 'group_members.is_talk_muted')
            ->addSelect([
                'latest_message_at' => $this->newest('created_at'),
                'latest_message_id' => $this->newest('id'),
                // The same read model the group list's per-row counts use, so the number here and
                // the number in the nav can never disagree about what unread means.
                'unread_talk_count' => UnreadTalkScope::correlate(
                    DB::table('group_messages')->selectRaw('count(*)'),
                    'group_members',
                    $viewerId,
                ),
            ])
            ->with('image')
            // Rooms that have been talked in lead. Spelled as a CASE rather than left to where the
            // engine collates NULL in a descending sort, which is not a portable answer.
            ->orderByRaw('case when latest_message_at is null then 1 else 0 end')
            ->orderByDesc('latest_message_at')
            ->orderByDesc('latest_message_id')
            // Nothing said yet: the join is the only event the room has, newest first.
            ->orderByDesc('group_members.created_at')
            ->orderByDesc('groups.id')
            ->paginate($perPage);

        return $page->setCollection($this->rooms($page->getCollection()));
    }

    /**
     * One column of the group's newest message, by the `(created_at, id)` tuple everything in talk
     * is ordered by.
     */
    private function newest(string $column): Builder
    {
        return DB::table('group_messages')
            ->select($column)
            ->whereColumn('group_messages.group_id', 'groups.id')
            ->orderByDesc('group_messages.created_at')
            ->orderByDesc('group_messages.id')
            ->limit(1);
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return Collection<int, TalkRoom>
     */
    private function rooms(Collection $groups): Collection
    {
        // The bodies for this page in one statement: the ordering already named the exact rows, so
        // this is a lookup by primary key rather than a "newest message" query per room.
        $ids = $groups->pluck('latest_message_id')->filter()->values()->all();
        $messages = $ids === []
            ? new Collection
            : GroupMessage::query()->whereIn('id', $ids)->with('author')->get()->keyBy('id');

        return $groups->map(fn (Group $group): TalkRoom => new TalkRoom(
            group: $group,
            unread: (int) $group->unread_talk_count,
            muted: (bool) $group->is_talk_muted,
            latest: $messages->get($group->latest_message_id),
        ));
    }
}
