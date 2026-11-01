<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;

/**
 * The owner's row is locked before the account's, and `owner_member_id` is immutable, so every
 * mint and sweep takes the two in the same order. The caller holds the transaction.
 */
final class ActorLock
{
    /** @return Member|null the locked row with its owner relation set from the locked owner row, or null when gone */
    public static function acquire(int $memberId): ?Member
    {
        $member = Member::find($memberId);

        if ($member === null) {
            return null;
        }

        $owner = null;

        if ($member->owner_member_id !== null) {
            $owner = Member::whereKey($member->owner_member_id)->lockForUpdate()->first();

            if ($owner === null) {
                return null;
            }
        }

        $actor = Member::whereKey($memberId)->lockForUpdate()->first();

        if ($actor === null) {
            return null;
        }

        if ($owner !== null) {
            // So the eligibility read uses the row this transaction locked, not a fresh unlocked one.
            $actor->setRelation('owner', $owner);
        }

        return $actor;
    }
}
