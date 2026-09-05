<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\DirectMessage\ConversationScope;
use App\Models\DirectMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fixed for the visit, since marking read fires seconds later and a boundary recomputed from the
 * receipts would race away from the reader (`docs/internals/direct-messages.md`, "Unread"). Nothing
 * waiting is null rather than a boundary with a count of zero.
 */
class ConversationUnreadSnapshot
{
    /** @return array{count: int, at: CarbonImmutable, id: int}|null */
    public function __invoke(Member $viewer, ?Member $counterpart): ?array
    {
        // One statement, so the count and the boundary describe the same instant: read separately, a
        // mark-read landing in between could report a boundary the count says is not there.
        $first = $this->unread($viewer, $counterpart)
            ->orderBy('created_at')
            ->orderBy('id')
            // The window counts the full unread set before LIMIT applies (MySQL 8 and SQLite alike).
            ->selectRaw('id, created_at, count(*) over () as waiting')
            ->first();

        if ($first === null) {
            return null;
        }

        return [
            'count' => (int) $first->waiting,
            'at' => CarbonImmutable::instance($first->created_at),
            'id' => (int) $first->getKey(),
        ];
    }

    /**
     * Only the received arm has unread state, and only a live receipt has any at all.
     *
     * @return Builder<DirectMessage>
     */
    private function unread(Member $viewer, ?Member $counterpart): Builder
    {
        $query = DirectMessage::query();
        ConversationScope::inbound($query, $counterpart);

        return $query->whereHas('recipients', fn (Builder $receipt) => $receipt
            ->where('recipient_id', $viewer->getKey())
            ->whereNull('read_at')
            ->recipientLive());
    }
}
