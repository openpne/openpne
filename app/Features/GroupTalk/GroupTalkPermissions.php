<?php

namespace App\Features\GroupTalk;

use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

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

    /** Kept out of `for()` so the write paths do not read a column only the talk page asks for. */
    public static function isMuted(Group $group, Member $member): bool
    {
        return (bool) DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->value('is_talk_muted');
    }

    /** Written by this member — a withdrawn author (null) is nobody's. */
    public function owns(GroupMessage $message): bool
    {
        return $message->member_id !== null && $message->member_id === $this->member->getKey();
    }

    /** An author who has since left the group keeps the ability to retract their own words. */
    public function canDelete(GroupMessage $message): bool
    {
        return $this->owns($message) || $this->canManage;
    }
}
