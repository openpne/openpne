<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Refused while any secret exists, pending or confirmed: rotating one under a concurrent confirm
 * would stamp the factor live against a secret the member never scanned. Restarting a pending set-up
 * is a cancel and then an enable, never a rotation in place.
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

            // A reset link must never survive a change in the factor's lifecycle
            // (docs/internals/security.md, "Member two-factor authentication").
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }
}
