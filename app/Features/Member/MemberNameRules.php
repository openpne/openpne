<?php

declare(strict_types=1);

namespace App\Features\Member;

/**
 * What a member's display name may be. Registration, profile edit and AI-account creation all write
 * members.name; stated once so the three cannot drift apart. 255 is the column's width.
 */
final class MemberNameRules
{
    /** @return array<int, string> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
