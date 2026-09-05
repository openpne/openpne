<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * For the two contexts with no second-factor proof to demand, the operator CLI and the reset link,
 * where {@see DisableMemberMfa} demands one; returns whether a live factor was removed. Every session
 * is revoked with no except-session, so a logged-in reset-link caller must log itself out afterwards.
 */
class ForceDisableMemberMfa
{
    use SyncsCallerInstance;

    public function __construct(private readonly DisableTwoFactorAuthentication $disable) {}

    public function __invoke(Member $member): bool
    {
        [$fresh, $wasEnabled] = DB::transaction(function () use ($member): array {
            $fresh = Member::whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            $wasEnabled = $fresh->hasEnabledTwoFactorAuthentication();

            ($this->disable)($fresh);

            // Removing a factor is a credential change: end every session so a stolen one cannot outlive
            // the reset, and rotate remember_token.
            SessionRevocation::revokeMember($fresh);

            // A reset link must never survive a change in the factor's lifecycle
            // (docs/internals/security.md, "Member two-factor authentication").
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return [$fresh, $wasEnabled];
        });

        $this->syncCaller($member, $fresh);

        return $wasEnabled;
    }
}
