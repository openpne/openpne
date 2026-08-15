<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;

/**
 * May this member act through a personal access token?
 *
 * An AI account is a foothold its owner holds, so a ban on the owner has to end it too: freezing a
 * member deletes the tokens of every account they own, and this is the belt behind that sweep — the
 * same reason App\Http\Middleware\EnsureTokenMemberNotFrozen refuses a frozen member's own surviving
 * token. One predicate rather than one per caller, so "who may hold a token" cannot be answered two
 * ways.
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
