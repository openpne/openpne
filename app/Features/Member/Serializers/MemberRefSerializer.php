<?php

namespace App\Features\Member\Serializers;

use App\Models\Member;

/**
 * The member reference an owner/scope prop carries: identity plus what it takes to draw an avatar,
 * so a page whose subject is a member (their archive, their roster) can show that member in the
 * chrome. Not a canonical shape for every author ref — feature serializers keep their own.
 */
class MemberRefSerializer
{
    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null} */
    public static function ref(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
            'avatarColor' => $member->avatar_color?->hex(),
        ];
    }
}
