<?php

namespace App\Listeners\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/** An admin is identified by its username, having no email; a member by its id. */
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
