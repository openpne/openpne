<?php

namespace App\Features\Member\Serializers;

use App\Models\Member;

/**
 * Feature serializers delegate here unless they say otherwise, so a fact added to a member reference
 * reaches every surface at once.
 */
class MemberRefSerializer
{
    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool} */
    public static function ref(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(120, 120, square: true),
            'avatarColor' => $member->avatar_color?->hex(),
            // Site policy: an AI account is recognisable wherever it speaks, so the reference always
            // carries the answer.
            'isAi' => $member->isAiAccount(),
        ];
    }
}
