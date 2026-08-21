<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads a group's talk by (created_at, id) keyset — the tuple the table is indexed on. Not an
 * offset pager: a chat is written to while it is being read, and an offset would shift a page under
 * the reader every time a message arrives.
 *
 * The tuple comparison is written out as `a > ? OR (a = ? AND b > ?)` rather than as SQL's row
 * constructor, which SQLite does not support.
 *
 * No per-row authorization: the group answered that once, for the whole conversation
 * (App\Features\GroupTalk\GroupTalkAccess), so the query cost does not grow with the page.
 */
class GroupTalkMessages
{
    /** Messages per page, in both directions. Not client-controlled. */
    public const PER_PAGE = 50;

    /**
     * The relations the serializer reads per row, in a fixed number of further queries whatever the
     * page size. Shared with {@see TouchedGroupMessages}, which serializes the same shape. Reactions
     * are not among them: they are counted in SQL rather than hydrated ({@see
     * MessageReactionAggregates}).
     *
     * @var list<string>
     */
    public const WITH = ['author.avatar.file', 'mentions', 'images.file', 'linkCard.image'];

    /**
     * How much of the conversation rides above the position an around() page opens on. A landing
     * drawn at the very top of a page reads as an accident of pagination; a few lines of what came
     * before make it read as a place in the conversation.
     */
    public const CONTEXT = 10;

    /** The newest page — what opening the talk shows. */
    public function latest(Group $group): GroupTalkPage
    {
        return $this->backwardsFrom($group, null);
    }

    /** The page ending just before $cursor — what "load older" asks for. */
    public function before(Group $group, GroupTalkCursor $cursor): GroupTalkPage
    {
        return $this->backwardsFrom($group, $cursor);
    }

    /**
     * Everything written since $cursor, oldest first — the poll. Capped like any other page: a tab
     * left open behind a busy group catches up one page per poll rather than in one unbounded read.
     */
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
     * The page a position sits in: the tail of the conversation up to and including $cursor, then
     * everything after it up to the cap. One contiguous slice, so the client can replace its list
     * with it and still be showing a conversation rather than two halves with a hole between them.
     *
     * Deliberately general — the unread boundary is only the first thing that needs to be opened on.
     * A cursor is a **position, not a permission** (the group's read gate already decided the
     * audience), so this takes one the client hands back exactly as `before` and `after` do; the
     * asymmetry is which side needs how much, not who may ask.
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

    /**
     * The newest page at or before $cursor. Read descending so the LIMIT takes the newest rows, then
     * reversed for display; one extra row answers whether anything remains further back.
     */
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
