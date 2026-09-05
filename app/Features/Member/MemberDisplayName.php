<?php

declare(strict_types=1);

namespace App\Features\Member;

use App\Models\Member;

/**
 * A string-only sink carries the AI marker inside the name, having no chip to draw beside it. A
 * surface that renders components ships `isAi` instead ({@see Serializers\MemberRefSerializer}), so
 * no name is marked twice.
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
