<?php

namespace App\Upgrade\Steps;

use App\Features\Group\GroupRole;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_member` (is_pre=0, confirmed) → OpenPNE 4 `group_members`.
 *
 * One source table feeds two targets by the is_pre flag: confirmed members here, pending applicants
 * in GroupJoinRequestUpgrade — the friendships / friend_requests split. Keeping the pending set
 * out of group_members is what makes a confirmed-member read safe without an extra filter.
 *
 * OpenPNE 3 modelled roles as separate community_member_position rows; the role column is recovered
 * with a correlated EXISTS per role, strongest first (admin beats sub_admin), driven by the runtime
 * GroupRole enum so the mapping cannot drift.
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
     * no talk history yet, so "read up to now, nothing muted" is the true state of every membership
     * it creates. When history does arrive, the transfer that brings it re-establishes the cursors —
     * the defaults are a backstop, not the initialization (docs/internals/group-talk.md).
     */
    public function targetDefaults(): array
    {
        return ['talk_read_at', 'talk_read_message_id', 'is_talk_muted'];
    }

    /**
     * community_member_position rows → the role int. A member with an `admin` position is Admin,
     * else `sub_admin` is SubAdmin, else a plain Member (OpenPNE 3 had no position row for members).
     * Built from GroupRole so a role/name change stays in one place; strongest role wins.
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
