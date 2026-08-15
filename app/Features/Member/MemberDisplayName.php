<?php

declare(strict_types=1);

namespace App\Features\Member;

use App\Models\Member;

/**
 * A member's name for a sink that can hold nothing but a string — a notification sentence, a push
 * body, a mail template variable. Site policy is that an AI account is recognisable as one wherever
 * it speaks, and those sinks have no chip to draw beside the name, so the marker travels inside the
 * string. A surface that renders components ships `isAi` instead ({@see Serializers\MemberRefSerializer})
 * and never sees this suffix, so no name is ever marked twice.
 *
 * Null-transparent: a withdrawn actor has no name here either, and the caller's own fallback stands.
 */
final class MemberDisplayName
{
    public static function of(?Member $member): ?string
    {
        if ($member === null) {
            return null;
        }

        return $member->isAiAccount()
            ? __(':name (AI)', ['name' => $member->name])
            : $member->name;
    }
}
