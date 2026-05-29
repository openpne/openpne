<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_relationship` (is_friend) → OpenPNE 4 `friendships`.
 *
 * OpenPNE 3 already stores a friendship as two mirrored rows — MemberRelationship::setFriend()
 * sets is_friend on both the from→to row and its to→from instance — so each is_friend row maps
 * directly to one friendships row; no UNION is needed to build OpenPNE 4's bidirectional mirror.
 *
 * The relation tables key on (member_id, friend_id) and track only created_at, so the source
 * surrogate id and updated_at are dropped; those gaps are recorded here for the shared table.
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

    public function gaps(): array
    {
        return [
            'id' => 'Surrogate key of the source link row; the relation tables use composite PKs.',
            'updated_at' => 'The relation tables track only created_at.',
        ];
    }
}
