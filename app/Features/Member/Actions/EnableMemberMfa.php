<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Start a two-factor set-up: write a pending secret + recovery codes. Strictly a disabled-state
 * action — with any secret present (pending or confirmed) a parallel enable could rotate the stored
 * secret under a concurrent confirm, which then stamps two_factor_confirmed_at against a secret the
 * member never scanned (a lockout). The row lock makes the check-and-write atomic against that race;
 * restarting a pending set-up is cancel (disable) first, then enable — never a rotation in place.
 *
 * The account-password re-auth and the MfaSetupReauth stamp are the controller's concern; this owns
 * only the guarded state transition.
 */
class EnableMemberMfa
{
    use SyncsCallerInstance;

    public function __construct(private readonly EnableTwoFactorAuthentication $enable) {}

    public function __invoke(Member $viewer): void
    {
        $fresh = DB::transaction(function () use ($viewer): Member {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            abort_if(! blank($fresh->two_factor_secret), 403);

            // Mutate the LOCKED row: Fortify's action re-checks the state of the model it is
            // handed, so a stale instance would let it silently no-op while the caller reports
            // success — the exact split the lock exists to prevent.
            ($this->enable)($fresh);

            // Defense-in-depth for invalidation contract (a): starting a fresh set-up drops any lingering
            // reset link (the disable that preceded this already did, but a reset link must never carry
            // across a factor's lifecycle).
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }
}
