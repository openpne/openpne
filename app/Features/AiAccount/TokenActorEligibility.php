<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;

/**
 * A ban on the owner ends an account's reach too, so this is the belt behind the sweep that deletes
 * their tokens (docs/internals/mcp.md, "Tokens and abilities").
 */
final class TokenActorEligibility
{
    public static function permits(Member $member): bool
    {
        if ($member->is_login_rejected) {
            return false;
        }

        // One extra query per request for an AI account, none for anyone else: Eloquent answers a
        // null foreign key without touching the database.
        return $member->owner?->is_login_rejected !== true;
    }
}
