<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Builder;

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
        $messages = $this->query($group)
            ->where(fn (Builder $q) => $q
                ->where('created_at', '>', $cursor->at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', '=', $cursor->at)
                    ->where('id', '>', $cursor->id)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::PER_PAGE)
            ->get();

        // Older rows are by definition already held by whoever is polling.
        return new GroupTalkPage($messages, hasOlder: false);
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
        // The author, their avatar and the mention ranges in three more queries, whatever the page
        // size — the serializer reads all three per row.
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->with('author.avatar.file', 'mentions');
    }
}
