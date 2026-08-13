<?php

namespace App\Features\GroupTalk;

use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;

/**
 * What one member may do in one group's talk, resolved from a single membership read and then asked
 * per message. A page renders a whole conversation, so the per-row questions ("is this mine", "may I
 * delete it") must not each cost a query — the role is read once and the rest is arithmetic.
 */
final readonly class GroupTalkPermissions
{
    private function __construct(
        public Member $member,
        public bool $canPost,
        public bool $canManage,
    ) {}

    public static function for(Group $group, Member $member): self
    {
        $role = GroupMembership::roleOf($group, $member);

        return new self($member, canPost: $role !== null, canManage: $role?->canManage() ?? false);
    }

    /** Written by this member — a withdrawn author (null) is nobody's. */
    public function owns(GroupMessage $message): bool
    {
        return $message->member_id !== null && $message->member_id === $this->member->getKey();
    }

    /**
     * May this member delete the message? Its author may, and so may anyone who manages the group —
     * a linear chat needs the moderation reach the boards already give. An author who has since left
     * the group keeps the ability to retract their own words.
     */
    public function canDelete(GroupMessage $message): bool
    {
        return $this->owns($message) || $this->canManage;
    }
}
