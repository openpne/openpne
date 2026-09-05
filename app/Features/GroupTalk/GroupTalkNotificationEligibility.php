<?php

namespace App\Features\GroupTalk;

use App\Features\Block\BlockLookup;
use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;

/**
 * Asked twice — at enqueue and again in `shouldSend()` before each channel — because a queued talk
 * mail carries the body and can outlive the facts it was enqueued under
 * (docs/internals/notifications.md, "Delivery-time re-checks"). Mute gates the broadcast and never
 * a mention, and a block gates both (docs/internals/group-talk.md, "What talk notifies").
 */
final class GroupTalkNotificationEligibility
{
    public static function canReceive(Member $recipient, Group $group, Member $author): bool
    {
        return ! $recipient->is($author)
            && ! $recipient->is_login_rejected
            && GroupMembership::isMember($group, $recipient)
            && GroupTalkAccess::canView($group, $recipient)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $author);
    }

    /**
     * Mute gates a broadcast, and a message the member has already read is dropped — a race rather
     * than a guarantee, since a read landing after this check still leaves the notification sent.
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
