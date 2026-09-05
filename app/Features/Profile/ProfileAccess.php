<?php

declare(strict_types=1);

namespace App\Features\Profile;

use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Support\Facades\Gate;

/**
 * `MemberPolicy` gates only the one-way block and so lets every signed-out reader through; the
 * web-public half here is what stops them (docs/internals/link-cards.md, "Who may see one").
 */
final class ProfileAccess
{
    public static function isWebPublic(Member $subject): bool
    {
        return $subject->profile_visibility === Visibility::Open;
    }

    /** For a caller with no page to redirect or abort from, where refusal means not existing. */
    public static function canView(?Member $viewer, Member $subject): bool
    {
        if ($viewer === null) {
            return self::isWebPublic($subject);
        }

        return Gate::forUser($viewer)->allows('access', $subject);
    }
}
