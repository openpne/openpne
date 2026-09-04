<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_relationship` (is_friend) → OpenPNE 4 `friendships`. OpenPNE 3 already stores a
 * friendship as two mirrored is_friend rows (MemberRelationship::setFriend()), so each maps to one
 * friendships row with no UNION.
 */
class FriendshipUpgrade extends UpgradeStep
{
    protected string $source = 'member_relationship';

    protected string $target = 'friendships';

    public function columns(): array
    {
        return [
            'member_id' => Column::source('member_id_from'),
            'friend_id' => Column::source('member_id_to'),
            'created_at' => Column::source('created_at'),
        ];
    }

    public function filter(): ?string
    {
        return 'is_friend = 1';
    }

    public function filterColumns(): array
    {
        return ['is_friend'];
    }

    /** Both ends: an invite friends the inviter to a member who has not activated yet. */
    public function memberRefs(): array
    {
        return ['member_id_from', 'member_id_to'];
    }

    public function gaps(): array
    {
        return [
            'id' => 'Surrogate key of the source link row; the relation tables use composite PKs.',
            'updated_at' => 'The relation tables track only created_at.',
        ];
    }
}
