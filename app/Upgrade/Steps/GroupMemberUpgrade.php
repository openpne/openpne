<?php

namespace App\Upgrade\Steps;

use App\Features\Group\GroupRole;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_member` (is_pre=0, confirmed) → OpenPNE 4 `group_members`; the pending
 * applicants (is_pre=1) go to GroupJoinRequestUpgrade, so a group_members read needs no extra
 * filter. OpenPNE 3 modelled roles as community_member_position rows, recovered by roleExpr().
 */
class GroupMemberUpgrade extends UpgradeStep
{
    protected string $source = 'community_member';

    protected string $target = 'group_members';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_id' => Column::source('community_id'),
            'member_id' => Column::source('member_id'),
            'role' => Column::expr($this->roleExpr(), uses: ['id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return 'is_pre = 0';
    }

    public function filterColumns(): array
    {
        return ['is_pre'];
    }

    /** The registration form joins the default groups one request before it activates. */
    public function memberRefs(): array
    {
        return ['member_id'];
    }

    public function gaps(): array
    {
        return [
            'is_receive_mail_pc' => 'Dropped: superseded by the member-level notification catalog (member_notification_settings, MemberNotificationSettingUpgrade) — community-post mail opt-ins are member-wide there, so this column\'s per-community granularity is not carried.',
            'is_receive_mail_mobile' => 'Mobile (feature-phone) post-notification opt-in; the mobile frontend is out of scope.',
        ];
    }

    /**
     * The talk columns are OpenPNE 4's own and stay at their schema defaults: an upgraded site has
     * no talk history, so "read up to now, nothing muted" is the true state of every membership it
     * creates (docs/internals/group-talk.md).
     */
    public function targetDefaults(): array
    {
        return ['talk_read_at', 'talk_read_message_id', 'is_talk_muted'];
    }

    /**
     * community_member_position rows → the role int, strongest first (admin beats sub_admin), else
     * Member — OpenPNE 3 had no position row for a plain member. Built from GroupRole so the mapping
     * cannot drift.
     */
    private function roleExpr(): string
    {
        $ranked = array_filter(
            GroupRole::cases(),
            static fn (GroupRole $role): bool => $role->op3PositionName() !== null,
        );
        usort($ranked, static fn (GroupRole $a, GroupRole $b): int => $b->value <=> $a->value);

        $whens = array_map(
            static fn (GroupRole $role): string => sprintf(
                'WHEN EXISTS (SELECT 1 FROM '.SourceRef::table('community_member_position').' `p` '
                ."WHERE `p`.`community_member_id` = `community_member`.`id` AND `p`.`name` = '%s') THEN %d",
                $role->op3PositionName(),
                $role->value,
            ),
            $ranked,
        );

        return 'CASE '.implode(' ', $whens).' ELSE '.GroupRole::Member->value.' END';
    }
}
