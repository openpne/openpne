<?php

namespace App\Features\DirectMessage\Actions;

use App\Features\DirectMessage\ConversationScope;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Exceptions\DirectMessageActionFailure;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record that the viewer has read this conversation as far as $messageId.
 *
 * The client names the last message it actually rendered and the server resolves its position, for
 * the reasons docs/internals/group-talk.md sets out for a talk: the conversation's current newest
 * would mark read whatever arrived since the page loaded, and a tuple sent by the client could erase
 * future unread. The named row may sit in either arm — the foot of a conversation is often the
 * viewer's own message — but only received messages carry read state, so only their receipts move.
 *
 * Monotonic by construction rather than by a guard: `read_at` only ever goes from null to a time, so
 * replaying an older report marks nothing (everything at or before it is already read) and a report
 * out of order cannot walk the boundary backwards.
 *
 * @throws DirectMessageActionException
 */
class MarkConversationRead
{
    /**
     * @param  Member|null  $counterpart  null = the withdrawn bucket
     * @return int receipts marked read
     */
    public function __invoke(Member $viewer, ?Member $counterpart, int $messageId): int
    {
        $foot = ConversationScope::apply(DirectMessage::query(), $viewer, $counterpart)
            ->find($messageId, ['id', 'created_at']);

        // A row this conversation cannot see resolves to no position at all rather than to someone
        // else's clock: another conversation's message, a draft, or one the viewer has trashed.
        if ($foot === null) {
            throw new DirectMessageActionException(DirectMessageActionFailure::UnknownMessage);
        }

        $at = CarbonImmutable::instance($foot->created_at);
        $id = (int) $foot->getKey();

        return DirectMessageRecipient::query()
            ->where('recipient_id', $viewer->getKey())
            ->whereNull('read_at')
            // A trashed receipt is not on the chat screen to be read, and restoring it from the
            // mailbox should hand back a message that has never been opened.
            ->recipientLive()
            ->whereHas('directMessage', function (Builder $message) use ($counterpart, $at, $id): void {
                ConversationScope::inbound($message, $counterpart);

                // At or before the named position, the tuple written out rather than as a row
                // constructor: SQLite has none, and created_at alone cannot separate one second.
                $message->where(fn (Builder $upTo) => $upTo
                    ->where('created_at', '<', $at)
                    ->orWhere(fn (Builder $tie) => $tie
                        ->where('created_at', '=', $at)
                        ->where('id', '<=', $id)));
            })
            ->update(['read_at' => now()]);
    }
}
