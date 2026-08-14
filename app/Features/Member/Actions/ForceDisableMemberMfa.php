<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Unconditionally clear a member's two-factor factor from a context where the member cannot present a
 * second-factor proof — operator lockout recovery (the CLI) and the admin-issued reset link. Returns
 * whether a LIVE (confirmed) factor was removed, so the caller can gate its log/alert on a real
 * credential change (a pending set-up or already-clear member is not). All sessions are revoked (no
 * except-session): both call sites act on a member who is not the one holding a browser session here
 * (the operator is at the CLI; the reset-link consumer's own-session teardown is the controller's).
 *
 * Distinct from the step-up {@see DisableMemberMfa}: that one runs in the member's own authenticated
 * session and REQUIRES the second-factor proof (fail-closed) precisely because a proof is available;
 * this one exists for the two contexts where it is not. Their contracts must not be merged.
 *
 * Invalidation contract (a): removing the factor also drops any pending reset link. The link proves
 * the factor that existed when it was issued; without this, a "send → disable → re-enable within the
 * TTL" sequence would leave the old link live against the new factor. Global lock order is
 * Member → mfa_reset_requests (this locks the Member row, then deletes the reset row), so a concurrent
 * {@see ConsumeMfaReset} — which locks Member first too — cannot deadlock against it.
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

            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return [$fresh, $wasEnabled];
        });

        $this->syncCaller($member, $fresh);

        return $wasEnabled;
    }
}
