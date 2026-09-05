<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keyset by `(created_at, id)`, never offset: a chat is written to while it is being read, so an
 * offset page would shift under the reader. The comparison is written out rather than as SQL's row
 * constructor, which SQLite does not support.
 */
class GroupTalkMessages
{
    /** The page size in both directions, and never client-controlled. */
    public const PER_PAGE = 50;

    /**
     * Shared with {@see TouchedGroupMessages}: a touched row replaces the client's whole row, so the
     * two have to serialize to the same shape.
     *
     * @var list<string>
     */
    public const WITH = ['author.avatar.file', 'mentions', 'images.file', 'linkCard.image'];

    public const CONTEXT = 10;

    public function latest(Group $group): GroupTalkPage
    {
        return $this->backwardsFrom($group, null);
    }

    public function before(Group $group, GroupTalkCursor $cursor): GroupTalkPage
    {
        return $this->backwardsFrom($group, $cursor);
    }

    public function after(Group $group, GroupTalkCursor $cursor): GroupTalkPage
    {
        $rows = $this->forwardFrom($group, $cursor);

        // Older rows are by definition already held by whoever is polling.
        return new GroupTalkPage(
            $rows->take(self::PER_PAGE)->values(),
            hasOlder: false,
            hasNewer: $rows->count() > self::PER_PAGE,
        );
    }

    /**
     * One contiguous slice, so the client can replace its list with it: a list assembled from two
     * stretches would draw the hole between them as conversation.
     */
    public function around(Group $group, GroupTalkCursor $cursor): GroupTalkPage
    {
        $read = $this->query($group)
            ->where(fn (Builder $q) => $q
                ->where('created_at', '<', $cursor->at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', '=', $cursor->at)
                    ->where('id', '<=', $cursor->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::CONTEXT + 1)
            ->get();

        $unread = $this->forwardFrom($group, $cursor);

        return new GroupTalkPage(
            $read->take(self::CONTEXT)->reverse()->values()->concat($unread->take(self::PER_PAGE))->values(),
            hasOlder: $read->count() > self::CONTEXT,
            hasNewer: $unread->count() > self::PER_PAGE,
        );
    }

    /**
     * Rows strictly after $cursor, oldest first, one over the cap so the caller can tell a full page
     * from an exhausted one.
     *
     * @return Collection<int, GroupMessage>
     */
    private function forwardFrom(Group $group, GroupTalkCursor $cursor): Collection
    {
        return $this->query($group)
            ->where(fn (Builder $q) => $q
                ->where('created_at', '>', $cursor->at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', '=', $cursor->at)
                    ->where('id', '>', $cursor->id)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::PER_PAGE + 1)
            ->get();
    }

    private function backwardsFrom(Group $group, ?GroupTalkCursor $cursor): GroupTalkPage
    {
        $query = $this->query($group);

        if ($cursor !== null) {
            $query->where(fn (Builder $q) => $q
                ->where('created_at', '<', $cursor->at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', '=', $cursor->at)
                    ->where('id', '<', $cursor->id)));
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE + 1)
            ->get();

        $hasOlder = $rows->count() > self::PER_PAGE;

        return new GroupTalkPage($rows->take(self::PER_PAGE)->reverse()->values(), $hasOlder);
    }

    /** @return Builder<GroupMessage> */
    private function query(Group $group): Builder
    {
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->with(self::WITH);
    }
}
