<?php

namespace App\Features\DirectMessage;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a conversation between the viewer and one counterpart contains — the single definition every
 * chat read path narrows by.
 *
 * The mailbox stores a message once (direct_messages) and its delivery per recipient
 * (direct_message_recipients); a conversation is the two directions of that pair composed back
 * together. Both arms select from direct_messages and test the receipts with EXISTS, rather than
 * unioning two derived tables in the FROM clause: a keyset page has to order and slice the composed
 * set, and a UNION subquery cannot be indexed for that on SQLite and MySQL alike.
 *
 * A null counterpart is the **withdrawn bucket**: the FKs are nullOnDelete, so every conversation
 * whose other side has left the site collapses into one, and the comparison switches to IS NULL
 * rather than binding a null that would make every row UNKNOWN.
 *
 * Visibility is per-side and nothing else. The sent arm reads only the sender's own columns and the
 * received arm only the recipient's own, so trashing your copy never removes it from theirs. Block is
 * deliberately not consulted — see docs/internals/direct-messages.md.
 */
class ConversationScope
{
    /**
     * @param  Builder<DirectMessage>  $query
     * @param  Member|null  $counterpart  null = the withdrawn bucket
     * @return Builder<DirectMessage>
     */
    public static function apply(Builder $query, Member $viewer, ?Member $counterpart): Builder
    {
        // A draft belongs to neither arm — it has no receipt at all, so it is invisible to the
        // recipient and stays in the sender's drafts box rather than in the conversation.
        return $query
            ->where('is_draft', false)
            ->where(fn (Builder $conversation) => $conversation
                ->where(fn (Builder $sent) => self::sent($sent, $viewer, $counterpart))
                ->orWhere(fn (Builder $received) => self::received($received, $viewer, $counterpart)));
    }

    /**
     * The message half of the received arm: delivered, and written by the counterpart. Public
     * because unread asks the same set from the receipt side — what is unread, and what a report may
     * mark read, is what this conversation received — and the two readings must not drift apart.
     *
     * @param  Builder<DirectMessage>  $query
     */
    public static function inbound(Builder $query, ?Member $counterpart): void
    {
        $query->where('is_draft', false);
        self::isCounterpart($query, 'sender_id', $counterpart);
    }

    /** What the viewer sent to the counterpart, minus what the viewer has trashed or purged. */
    private static function sent(Builder $query, Member $viewer, ?Member $counterpart): void
    {
        $query
            ->where('sender_id', $viewer->getKey())
            ->whereNull('sender_deleted_at')
            ->whereNull('sender_purged_at')
            ->whereHas('recipients', fn (Builder $receipt) => self::isCounterpart($receipt, 'recipient_id', $counterpart));
    }

    /** What the counterpart sent the viewer, minus what the viewer has trashed or purged. */
    private static function received(Builder $query, Member $viewer, ?Member $counterpart): void
    {
        self::inbound($query, $counterpart);

        $query->whereHas('recipients', fn (Builder $receipt) => $receipt
            ->where('recipient_id', $viewer->getKey())
            ->recipientLive());
    }

    /**
     * @param  Builder<DirectMessage>|Builder<DirectMessageRecipient>  $query
     */
    private static function isCounterpart(Builder $query, string $column, ?Member $counterpart): void
    {
        $counterpart === null ? $query->whereNull($column) : $query->where($column, $counterpart->getKey());
    }
}
