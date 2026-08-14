<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\DirectMessage\ConversationScope;
use App\Models\DirectMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the conversation's unread boundary stood when the page was rendered: how many received
 * messages are waiting, and the oldest of them.
 *
 * The boundary is the **first unread message**, not a read-through position, because a conversation
 * holds no cursor to read one from. Read state lives on each receipt, and the mailbox opens messages
 * one at a time, so `read_at` is full of holes — an older message left unread under newer read ones
 * is an ordinary state here, and the only line that means anything is the one above the oldest thing
 * still waiting.
 *
 * A *snapshot*, and the page treats it as one (docs/internals/direct-messages.md): marking read
 * fires seconds later, and a boundary recomputed from the receipts would race away from the reader.
 * Nothing waiting is null rather than a boundary with a count of zero — there is no position to
 * report when no message holds one.
 */
class ConversationUnreadSnapshot
{
    /** @return array{count: int, at: CarbonImmutable, id: int}|null */
    public function __invoke(Member $viewer, ?Member $counterpart): ?array
    {
        $first = $this->unread($viewer, $counterpart)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'created_at']);

        if ($first === null) {
            return null;
        }

        return [
            'count' => $this->unread($viewer, $counterpart)->count(),
            'at' => CarbonImmutable::instance($first->created_at),
            'id' => (int) $first->getKey(),
        ];
    }

    /**
     * What is unread in this conversation: a message it received whose receipt the viewer has not
     * opened. The viewer's own messages hold no unread state at all — writing is not something to be
     * read — and a receipt the viewer has trashed is not on the screen to be read either.
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
