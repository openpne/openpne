<?php

namespace App\Features\GroupTalk;

use App\Features\Block\BlockLookup;
use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\Member;

/**
 * Whether a member may receive a talk notification right now. Talk sends exactly one kind — a
 * mention — so this is that kind's predicate, but it is stated separately because it is asked twice:
 * when the listener enqueues, and again in the notification's shouldSend() immediately before each
 * channel delivers. A mention mail carries the message body, and a notification that queues can
 * outlive the facts it was enqueued under — a ban, a fresh block, a member leaving the group.
 *
 * **Mute is deliberately not consulted.** `is_talk_muted` silences the room's unread badge; a
 * mention is addressed to one person, and being named outranks having asked the room for quiet. A
 * member who wants no mention mail turns the catalog kind off, which is a different question and is
 * honoured by the channel selection.
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
}
