<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\DirectMessage\ConversationCursor;
use App\Features\DirectMessage\ConversationPage;
use App\Features\DirectMessage\ConversationScope;
use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads one conversation by (created_at, id) keyset. Not an offset pager: a conversation is written
 * to while it is being read, and an offset would shift a page under the reader every time a message
 * arrives. Ordering by id alone would be wrong on upgraded content — rows arrive in transfer order,
 * not chronological order — and created_at alone is not a total order at MySQL's one-second
 * timestamp resolution.
 *
 * The tuple comparison is written out as `a > ? OR (a = ? AND b > ?)` rather than as SQL's row
 * constructor, which SQLite does not support.
 *
 * No per-row authorization: ConversationScope already answered who may see each row, as the two
 * sides' own trash columns, so the query cost does not grow with the page.
 */
class ConversationMessages
{
    /** Messages per page, in both directions. Not client-controlled. */
    public const PER_PAGE = 50;

    /**
     * How much of the conversation rides above the position an around() page opens on. A landing
     * drawn at the very top of a page reads as an accident of pagination; a few lines of what came
     * before make it read as a place in the conversation.
     */
    public const CONTEXT = 10;

    /** The newest page — what opening the conversation shows. */
    public function latest(Member $viewer, ?Member $counterpart): ConversationPage
    {
        return $this->backwardsFrom($viewer, $counterpart, null);
    }

    /** The page ending just before $cursor — what "load older" asks for. */
    public function before(Member $viewer, ?Member $counterpart, ConversationCursor $cursor): ConversationPage
    {
        return $this->backwardsFrom($viewer, $counterpart, $cursor);
    }

    /**
     * Everything written since $cursor, oldest first — the poll. Capped like any other page: a tab
     * left open behind a busy conversation catches up one page per poll rather than in one unbounded
     * read.
     */
    public function after(Member $viewer, ?Member $counterpart, ConversationCursor $cursor): ConversationPage
    {
        $rows = $this->forwardFrom($viewer, $counterpart, $cursor);

        // Older rows are by definition already held by whoever is polling.
        return new ConversationPage(
            $rows->take(self::PER_PAGE)->values(),
            hasOlder: false,
            hasNewer: $rows->count() > self::PER_PAGE,
        );
    }

    /**
     * The page a position sits in: the tail of the conversation up to and including $cursor, then
     * everything after it up to the cap. One contiguous slice, so the client can replace its list
     * with it and still be showing a conversation rather than two halves with a hole between them.
     */
    public function around(Member $viewer, ?Member $counterpart, ConversationCursor $cursor): ConversationPage
    {
        $read = $this->query($viewer, $counterpart)
            ->where(fn (Builder $q) => $q
                ->where('created_at', '<', $cursor->at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', '=', $cursor->at)
                    ->where('id', '<=', $cursor->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::CONTEXT + 1)
            ->get();

        $after = $this->forwardFrom($viewer, $counterpart, $cursor);

        return new ConversationPage(
            $read->take(self::CONTEXT)->reverse()->values()->concat($after->take(self::PER_PAGE))->values(),
            hasOlder: $read->count() > self::CONTEXT,
            hasNewer: $after->count() > self::PER_PAGE,
        );
    }

    /**
     * Rows strictly after $cursor, oldest first, one over the cap so the caller can tell a full page
     * from an exhausted one.
     *
     * @return Collection<int, DirectMessage>
     */
    private function forwardFrom(Member $viewer, ?Member $counterpart, ConversationCursor $cursor): Collection
    {
        return $this->query($viewer, $counterpart)
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
    private function backwardsFrom(Member $viewer, ?Member $counterpart, ?ConversationCursor $cursor): ConversationPage
    {
        $query = $this->query($viewer, $counterpart);

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

        return new ConversationPage($rows->take(self::PER_PAGE)->reverse()->values(), $rows->count() > self::PER_PAGE);
    }

    /** @return Builder<DirectMessage> */
    private function query(Member $viewer, ?Member $counterpart): Builder
    {
        // The author, their avatar, the attachments and the receipts in a fixed number of further
        // queries, whatever the page size — the serializer reads them all per row. `recipients` is
        // unconstrained so the serializer stays the one place that says which receipt answers for
        // this conversation.
        return ConversationScope::apply(
            DirectMessage::query()->with('sender.avatar.file', 'files.file', 'recipients'),
            $viewer,
            $counterpart,
        );
    }
}
