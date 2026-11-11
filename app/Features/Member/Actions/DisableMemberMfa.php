<?php

namespace App\Features\Member\Actions;

use App\Auth\Reauth\ReauthProof;
use App\Auth\Reauth\VerifiesReauthProof;
use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Returns whether a live (confirmed) factor was removed; only that is a credential change to revoke
 * sessions and alert over. The proof is verified inside the lock the factor is removed under.
 */
class DisableMemberMfa
{
    use SyncsCallerInstance, VerifiesReauthProof;

    public function __construct(private readonly DisableTwoFactorAuthentication $disable) {}

    public function __invoke(Member $viewer, ReauthProof $proof, ?string $exceptSessionId): bool
    {
        [$fresh, $wasEnabled] = DB::transaction(function () use ($viewer, $proof, $exceptSessionId): array {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            // Nothing to disable — don't revoke sessions over a no-op (stale tab double-submit).
            if (blank($fresh->two_factor_secret)) {
                return [$fresh, false];
            }

            $wasEnabled = $fresh->hasEnabledTwoFactorAuthentication();

            // A request validated as a pending cancel carries no proof, so a factor that went live
            // under it fails closed here.
            if ($wasEnabled) {
                $this->verifyReauthProof($fresh, $proof);
            }

            ($this->disable)($fresh);
            if ($wasEnabled) {
                SessionRevocation::revokeMember($fresh, $exceptSessionId);
            }

            // A reset link must never survive a change in the factor's lifecycle
            // (docs/internals/security.md, "Member two-factor authentication").
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return [$fresh, $wasEnabled];
        });

        $this->syncCaller($viewer, $fresh);

        return $wasEnabled;
    }
}
