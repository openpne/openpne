<?php

declare(strict_types=1);

namespace App\Features\Profile;

use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Support\Facades\Gate;

/**
 * Whether a viewer may reach a member's profile page at all — the page-level gate, both halves of it.
 *
 * The two halves answer different readers and are easy to mistake for one another. `MemberPolicy`
 * gates only the one-way block, and a guest is nobody to block, so it lets every signed-out reader
 * through by design; what stops them is the web-public switch on the profile itself. A caller that
 * asked only the policy would show a members-only profile to the world.
 *
 * {@see ProfileController} states the same two conditions in the order a page needs them, because
 * they have different answers there — a guest is redirected to log in, a blocked viewer gets a 404 —
 * and it reads the web-public half from here so the rule is written down once.
 */
final class ProfileAccess
{
    /** Whether $subject's profile is one a signed-out reader may open. */
    public static function isWebPublic(Member $subject): bool
    {
        return $subject->profile_visibility === Visibility::Open;
    }

    /**
     * Whether $viewer may read $subject's profile page.
     *
     * For callers with no page to redirect or abort from — a link card naming a member — where both
     * halves collapse into one answer and refusal means the same thing as not existing.
     */
    public static function canView(?Member $viewer, Member $subject): bool
    {
        if ($viewer === null) {
            return self::isWebPublic($subject);
        }

        return Gate::forUser($viewer)->allows('access', $subject);
    }
}
