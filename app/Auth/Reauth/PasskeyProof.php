<?php

namespace App\Auth\Reauth;

use App\Models\Member;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** See docs/internals/security.md, "Member re-authentication". */
class PasskeyProof
{
    public const SLOT = 'reauth.passkey_proof';

    private const TTL_SECONDS = 120;

    public static function issue(Session $session, Member $member, ReauthPurpose $purpose): void
    {
        $session->put(self::SLOT, [
            'member_id' => $member->getKey(),
            'purpose' => $purpose->value,
            'nonce' => Str::random(40),
            'at' => now()->getTimestamp(),
        ]);
    }

    public static function stands(Session $session, Member $member, ReauthPurpose $purpose): bool
    {
        return self::pending($session, $member, $purpose) !== null;
    }

    /**
     * True for exactly one request: the session is not locked, so parallel requests read the same
     * proof and the cache's atomic add picks the winner.
     */
    public static function consume(Session $session, Member $member, ReauthPurpose $purpose): bool
    {
        $nonce = self::pending($session, $member, $purpose);

        if ($nonce === null) {
            return false;
        }

        $session->forget(self::SLOT);

        return Cache::add('reauth-proof|'.$member->getKey().'|'.$nonce, 1, self::TTL_SECONDS);
    }

    private static function pending(Session $session, Member $member, ReauthPurpose $purpose): ?string
    {
        $proof = $session->get(self::SLOT);

        if (! is_array($proof) || ! isset($proof['member_id'], $proof['purpose'], $proof['nonce'], $proof['at'])) {
            return null;
        }

        $age = now()->getTimestamp() - (int) $proof['at'];

        return $proof['member_id'] === $member->getKey() && $proof['purpose'] === $purpose->value
            && $age >= 0 && $age <= self::TTL_SECONDS ? (string) $proof['nonce'] : null;
    }
}
