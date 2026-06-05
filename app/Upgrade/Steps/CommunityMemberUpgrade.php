<?php

namespace App\Upgrade\Steps;

use App\Features\Community\CommunityRole;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_member` (is_pre=0, confirmed) → OpenPNE 4 `community_members`.
 *
 * One source table feeds two targets by the is_pre flag: confirmed members here, pending applicants
 * in CommunityJoinRequestUpgrade — the friendships / friend_requests split. Keeping the pending set
 * out of community_members is what makes a confirmed-member read safe without an extra filter.
 *
 * OpenPNE 3 modelled roles as separate community_member_position rows; the role column is recovered
 * with a correlated EXISTS per role, strongest first (admin beats sub_admin), driven by the runtime
 * CommunityRole enum so the mapping cannot drift. The position subquery names the table unqualified
 * (same fleet caveat as the other config subqueries).
 */
class CommunityMemberUpgrade extends UpgradeStep
{
    protected string $source = 'community_member';

    protected string $target = 'community_members';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'community_id' => Column::source('community_id'),
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

    public function gaps(): array
    {
        return [
            'is_receive_mail_pc' => 'Per-member community-post mail opt-in; lands with the notification feature.',
            'is_receive_mail_mobile' => 'Mobile (feature-phone) post-notification opt-in; the mobile frontend is out of scope.',
        ];
    }

    /**
     * community_member_position rows → the role int. A member with an `admin` position is Admin,
     * else `sub_admin` is SubAdmin, else a plain Member (OpenPNE 3 had no position row for members).
     * Built from CommunityRole so a role/name change stays in one place; strongest role wins.
     */
    private function roleExpr(): string
    {
        $ranked = array_filter(
            CommunityRole::cases(),
            static fn (CommunityRole $role): bool => $role->op3PositionName() !== null,
        );
        usort($ranked, static fn (CommunityRole $a, CommunityRole $b): int => $b->value <=> $a->value);

        $whens = array_map(
            static fn (CommunityRole $role): string => sprintf(
                'WHEN EXISTS (SELECT 1 FROM `community_member_position` `p` '
                ."WHERE `p`.`community_member_id` = `community_member`.`id` AND `p`.`name` = '%s') THEN %d",
                $role->op3PositionName(),
                $role->value,
            ),
            $ranked,
        );

        return 'CASE '.implode(' ', $whens).' ELSE '.CommunityRole::Member->value.' END';
    }
}
