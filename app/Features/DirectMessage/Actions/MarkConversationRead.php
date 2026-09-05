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
 * See `docs/internals/direct-messages.md`, "Mark-read".
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

                // Written out rather than as a row constructor, which SQLite has no support for.
                $message->where(fn (Builder $upTo) => $upTo
                    ->where('created_at', '<', $at)
                    ->orWhere(fn (Builder $tie) => $tie
                        ->where('created_at', '=', $at)
                        ->where('id', '<=', $id)));
            })
            ->update(['read_at' => now()]);
    }
}
