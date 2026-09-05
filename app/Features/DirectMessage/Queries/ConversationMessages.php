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
 * Paged by the `(created_at, id)` keyset, written out rather than as a row constructor
 * (`docs/internals/direct-messages.md`, "Ordering and paging"). No per-row authorization:
 * `ConversationScope` already answered who may see each row, so the cost does not grow with the page.
 */
class ConversationMessages
{
    /** Not client-controlled. */
    public const PER_PAGE = 50;

    public const CONTEXT = 10;

    public function latest(Member $viewer, ?Member $counterpart): ConversationPage
    {
        return $this->backwardsFrom($viewer, $counterpart, null);
    }

    public function before(Member $viewer, ?Member $counterpart, ConversationCursor $cursor): ConversationPage
    {
        return $this->backwardsFrom($viewer, $counterpart, $cursor);
    }

    /**
     * Capped like any other page, so a tab left open behind a busy conversation catches up one page
     * per poll rather than in one unbounded read.
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
     * One contiguous slice, so the client can replace its list with it rather than end up holding
     * two halves with a hole between them.
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
     * One row over the cap, so the caller can tell a full page from an exhausted one.
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
     * Read descending so the LIMIT takes the newest rows, then reversed for display; one extra row
     * answers whether anything remains further back.
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
        // `recipients` is unconstrained so the serializer stays the one place that says which
        // receipt answers for this conversation.
        return ConversationScope::apply(
            DirectMessage::query()->with('sender.avatar.file', 'files.file', 'recipients'),
            $viewer,
            $counterpart,
        );
    }
}
