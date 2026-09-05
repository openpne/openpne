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
 * The order is decided in SQL before the page is cut: paging the groups first and then reading their
 * newest messages sorts the page rather than the membership. The newest tuple rides along as two
 * correlated subselects because reading it in one needs a row constructor or a lateral join, and
 * SQLite has neither.
 */
class JoinedTalkRooms
{
    public const PER_PAGE = 20;

    /**
     * A caller with no request behind it must name $page, since `paginate()` otherwise reads it off a
     * request that is not there.
     *
     * @return LengthAwarePaginator<int, TalkRoom>
     */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE, ?int $page = null, bool $withUnreadMentions = false): LengthAwarePaginator
    {
        $paginator = $this->ordered($viewer, $withUnreadMentions)->paginate($perPage, page: $page);

        return $paginator->setCollection($this->rooms($paginator->getCollection(), $withUnreadMentions));
    }

    /** @return Collection<int, TalkRoom> */
    public function take(Member $viewer, int $limit): Collection
    {
        return $this->rooms($this->ordered($viewer)->limit($limit)->get());
    }

    /**
     * $withUnreadMentions buys a third correlated subselect, so it stays off for the reads that run
     * on every page.
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
            // A CASE rather than a reliance on where the engine collates NULL in a descending sort,
            // which is not a portable answer.
            ->orderByRaw('case when latest_message_at is null then 1 else 0 end')
            ->orderByDesc('latest_message_at')
            ->orderByDesc('latest_message_id')
            ->orderByDesc('group_members.created_at')
            ->orderByDesc('groups.id');
    }

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
     * A semi-join rather than a join, and one OR rather than two counts, so a message that both names
     * the viewer and answers them is one unread message. Both arms are written outwards from
     * `group_messages` because that direction is indexed on both engines: `group_message_id` leads
     * the mentions table's unique index, which on SQLite is the only index there.
     */
    private function unreadMentions(int $viewerId): Builder
    {
        return UnreadTalkScope::correlate(
            DB::table('group_messages')
                ->selectRaw('count(*)')
                ->where(fn (Builder $addressed) => $addressed
                    ->whereExists(fn (Builder $named) => $named
                        ->from('group_message_mentions')
                        ->whereColumn('group_message_mentions.group_message_id', 'group_messages.id')
                        ->where('group_message_mentions.member_id', $viewerId))
                    // The parent is matched on its group as well as its id: `in_reply_to_id` carries
                    // no foreign key, so a row that is gone leaves the id behind and only a live
                    // message of this room may answer for it.
                    ->orWhereExists(fn (Builder $answered) => $answered
                        ->from('group_messages as parents')
                        ->whereColumn('parents.id', 'group_messages.in_reply_to_id')
                        ->whereColumn('parents.group_id', 'group_messages.group_id')
                        ->where('parents.member_id', $viewerId))),
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
        // The ordering already named the exact rows, so the bodies are one lookup by primary key
        // rather than a "newest message" query per room.
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
