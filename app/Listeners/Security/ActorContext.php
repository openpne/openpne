<?php

namespace App\Listeners\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the realm-appropriate actor field for the guard-tagged auth events: an admin is
 * identified by its username (it has no email), a member by its id. An absent user contributes
 * nothing (a logout with no bound user, say).
 */
final class ActorContext
{
    /** @return array<string, mixed> */
    public static function of(string $guard, ?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        return $guard === 'admin'
            ? ['username' => $user->username]
            : ['member_id' => $user->getAuthIdentifier()];
    }
}
