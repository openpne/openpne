<?php

namespace App\Features\GroupTalk;

use App\Features\Block\BlockLookup;
use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;

/**
 * Whether a member may receive a talk notification right now. It is stated separately because it is
 * asked twice: when the sender enqueues, and again in the notification's shouldSend() immediately
 * before each channel delivers. A talk mail carries the message body, and a notification that queues
 * can outlive the facts it was enqueued under — a ban, a fresh block, a member leaving the group.
 *
 * **Mute is deliberately not consulted here.** `is_talk_muted` silences the room's unread badge; a
 * mention is addressed to one person, and being named outranks having asked the room for quiet. A
 * member who wants no mention mail turns the catalog kind off, which is a different question and is
 * honoured by the channel selection. The per-message broadcast answers the opposite way — see
 * canReceiveBroadcast().
 *
 * Blocking, by contrast, IS consulted here — and is the reason this lives outside GroupTalkAccess.
 * Talk history applies no per-row block filter (a conversation with holes is not the conversation
 * that happened), but delivery is not history: a block means these two are not to be put in front of
 * each other, and a notification is exactly that.
 */
final class GroupTalkNotificationEligibility
{
    public static function canReceive(Member $recipient, Group $group, Member $author): bool
    {
        return ! $recipient->is($author)
            && ! $recipient->is_login_rejected
            // The room is the audience: someone who has left is no longer part of the conversation,
            // and the opt-out they would reach for is not theirs to hold from outside.
            && GroupMembership::isMember($group, $recipient)
            && GroupTalkAccess::canView($group, $recipient)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $author);
    }

    /**
     * The same question for the per-message broadcast (docs/internals/group-talk.md), which adds two
     * conditions a mention does not carry:
     *
     * - **Mute does gate it.** A broadcast is addressed to the room, so asking the room for quiet is
     *   exactly an answer to it — the asymmetry with canReceive() above is the point.
     * - **A message the member has already read is not delivered.** The job runs after a short grace
     *   so someone sitting in the room usually never hears about what they just read. It is a race,
     *   not a guarantee: a read landing after this check still leaves the notification sent.
     */
    public static function canReceiveBroadcast(Member $recipient, GroupMessage $message, Member $author): bool
    {
        $group = $message->group;

        return $group !== null
            && self::canReceive($recipient, $group, $author)
            && ! GroupTalkPermissions::isMuted($group, $recipient)
            && TalkReadCursor::isBehind(
                (int) $message->group_id,
                (int) $recipient->getKey(),
                CarbonImmutable::instance($message->created_at),
                (int) $message->getKey(),
            );
    }
}
