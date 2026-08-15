<?php

namespace App\Features\Member\Serializers;

use App\Models\Member;

/**
 * The canonical shape every member reference travels in — an author byline, a roster row, a picker
 * candidate, the owner a page is about: identity, what it takes to draw the avatar, and whether the
 * account is an AI one. Feature serializers delegate here rather than assembling their own, so a
 * fact this reference must carry reaches every surface at once instead of one sweep at a time.
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
            // Site policy: an AI account is recognisable as one wherever it speaks. Carried on the
            // reference itself so no surface can render a member without the answer in hand.
            'isAi' => $member->isAiAccount(),
        ];
    }
}
