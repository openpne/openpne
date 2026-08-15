<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\TalkRoom;
use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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

    /**
     * $page is null for a caller that has a URL behind it — paginate() then reads `?page=` as it
     * always did. A caller with no request (the MCP tool) names the page, or every call would answer
     * the first one.
     *
     * @return LengthAwarePaginator<int, TalkRoom>
     */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE, ?int $page = null, bool $withUnreadMentions = false): LengthAwarePaginator
    {
        $paginator = $this->ordered($viewer, $withUnreadMentions)->paginate($perPage, page: $page);

        return $paginator->setCollection($this->rooms($paginator->getCollection(), $withUnreadMentions));
    }

    /**
     * The leading rooms alone, for a digest that has no pager: no total to count, and no page
     * number read off the URL of a screen that has none.
     *
     * @return Collection<int, TalkRoom>
     */
    public function take(Member $viewer, int $limit): Collection
    {
        return $this->rooms($this->ordered($viewer)->limit($limit)->get());
    }

    /**
     * The viewer's rooms in room order, before any page is cut — shared with the nav's lighter slice
     * (NavTalkRooms), which takes the same rows and stops there.
     *
     * $withUnreadMentions buys a third correlated subselect and is off for everything the nav
     * renders: this query runs on every page, and "how many of those are addressed to me" is a
     * question only a caller with no screen to look at asks (the MCP room list).
     *
     * @return EloquentBuilder<Group>
     */
    public function ordered(Member $viewer, bool $withUnreadMentions = false): EloquentBuilder
    {
        $viewerId = (int) $viewer->getKey();

        return Group::query()
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
            ->when($withUnreadMentions, fn (EloquentBuilder $query) => $query->addSelect([
                'unread_mention_count' => $this->unreadMentions($viewerId),
            ]))
            ->with('image')
            // Rooms that have been talked in lead. Spelled as a CASE rather than left to where the
            // engine collates NULL in a descending sort, which is not a portable answer.
            ->orderByRaw('case when latest_message_at is null then 1 else 0 end')
            ->orderByDesc('latest_message_at')
            ->orderByDesc('latest_message_id')
            // Nothing said yet: the join is the only event the room has, newest first.
            ->orderByDesc('group_members.created_at')
            ->orderByDesc('groups.id');
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
     * The subset of the unread that names the viewer — the same unread predicate, narrowed to
     * messages carrying a mention row of theirs.
     *
     * A semi-join rather than a join: it counts *messages*, so being named twice in one line is one
     * unread message, the unit `unread_talk_count` already answers in. Written outwards from
     * `group_messages` because that direction is indexed on both engines — `group_message_id` leads
     * the mentions table's unique index, and on SQLite that is the only index there, a foreign key
     * getting none of its own. MySQL, which does index the key, flattens the EXISTS and may drive
     * from the viewer's own mention rows instead; either direction is an index lookup.
     */
    private function unreadMentions(int $viewerId): Builder
    {
        return UnreadTalkScope::correlate(
            DB::table('group_messages')
                ->selectRaw('count(*)')
                ->whereExists(fn (Builder $named) => $named
                    ->from('group_message_mentions')
                    ->whereColumn('group_message_mentions.group_message_id', 'group_messages.id')
                    ->where('group_message_mentions.member_id', $viewerId)),
            'group_members',
            $viewerId,
        );
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return Collection<int, TalkRoom>
     */
    private function rooms(Collection $groups, bool $withUnreadMentions = false): Collection
    {
        // The bodies for this page in one statement: the ordering already named the exact rows, so
        // this is a lookup by primary key rather than a "newest message" query per room.
        //
        // Whether there are pictures, not how many: the preview's stand-in for a message with no
        // words never counts them (App\Support\ChatPreview).
        $ids = $groups->pluck('latest_message_id')->filter()->values()->all();
        $messages = $ids === []
            ? new Collection
            : GroupMessage::query()->whereIn('id', $ids)->with('author')->withExists('images')->get()->keyBy('id');

        return $groups->map(fn (Group $group): TalkRoom => new TalkRoom(
            group: $group,
            unread: (int) $group->unread_talk_count,
            muted: (bool) $group->is_talk_muted,
            latest: $messages->get($group->latest_message_id),
            unreadMentions: $withUnreadMentions ? (int) $group->unread_mention_count : null,
        ));
    }
}
