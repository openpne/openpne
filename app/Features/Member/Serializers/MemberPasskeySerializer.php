<?php

namespace App\Features\Member\Serializers;

use App\Features\Member\PasskeyReauth;
use App\Models\Member;
use Illuminate\Contracts\Session\Session;
use Laravel\Passkeys\Passkey;

class MemberPasskeySerializer
{
    /** @return array<string, mixed> */
    public static function state(Member $member, Session $session): array
    {
        $passkeys = $member->passkeys()->oldest('id')->get();

        return [
            'passkeys' => $passkeys->map(fn (Passkey $passkey): array => [
                'id' => $passkey->getKey(),
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'synced' => self::isSynced($passkey),
                'createdAt' => $passkey->created_at?->toIso8601String(),
                'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
            ])->all(),
            'requiresPassword' => ! PasskeyReauth::isFresh($session),
            'requiresSecondFactor' => $member->hasEnabledTwoFactorAuthentication(),
            'deviceBoundOnly' => $passkeys->isNotEmpty() && $passkeys->every(fn (Passkey $passkey): bool => ! self::isSynced($passkey)),
        ];
    }

    /** The WebAuthn backup-state flag as last seen from the authenticator (registration or a later assertion). */
    private static function isSynced(Passkey $passkey): bool
    {
        return (bool) ($passkey->credential['backupStatus'] ?? false);
    }
}
