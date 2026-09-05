<?php

namespace App\Features\DirectMessage;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of what a conversation contains: a read path that narrows further is
 * answering a different question
 * (`docs/internals/direct-messages.md`, "A conversation is two arms over one table").
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
        // A draft has no receipt, so this is a belt against a stray one rather than what excludes it.
        return $query
            ->where('is_draft', false)
            ->where(fn (Builder $conversation) => $conversation
                ->where(fn (Builder $sent) => self::sent($sent, $viewer, $counterpart))
                ->orWhere(fn (Builder $received) => self::received($received, $viewer, $counterpart)));
    }

    /**
     * Public because unread and mark-read ask the same set from the receipt side, and the two
     * readings must not drift apart.
     *
     * @param  Builder<DirectMessage>  $query
     */
    public static function inbound(Builder $query, ?Member $counterpart): void
    {
        $query->where('is_draft', false);
        self::isCounterpart($query, 'sender_id', $counterpart);
    }

    /**
     * Public because deleting a conversation moves the viewer's own side of exactly these rows, and
     * the two readings must not drift apart.
     *
     * @param  Builder<DirectMessage>  $query
     */
    public static function outbound(Builder $query, Member $viewer, ?Member $counterpart): void
    {
        $query
            ->where('is_draft', false)
            ->where('sender_id', $viewer->getKey())
            ->whereHas('recipients', fn (Builder $receipt) => self::isCounterpart($receipt, 'recipient_id', $counterpart));
    }

    private static function sent(Builder $query, Member $viewer, ?Member $counterpart): void
    {
        self::outbound($query, $viewer, $counterpart);

        $query->whereNull('sender_deleted_at')->whereNull('sender_purged_at');
    }

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
