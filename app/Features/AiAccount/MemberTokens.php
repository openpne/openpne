<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;
use Laravel\Passport\Passport;

/**
 * Every credential a member holds against the token realm, whichever guard minted it. Rows are
 * deleted, not flagged, so the caller's transaction decides whether the sweep stands.
 */
final class MemberTokens
{
    /** @return int access tokens removed, personal and OAuth together */
    public static function deleteAll(Member $member): int
    {
        return $member->tokens()->delete() + self::deleteOAuth($member);
    }

    /** @return int OAuth access tokens removed */
    public static function deleteOAuth(Member $member): int
    {
        $id = $member->getKey();
        $accessTokens = Passport::token()->newQuery()->where('user_id', $id);

        Passport::refreshToken()->newQuery()
            ->whereIn('access_token_id', $accessTokens->clone()->select('id'))
            ->delete();
        Passport::authCode()->newQuery()->where('user_id', $id)->delete();

        return $accessTokens->delete();
    }
}
