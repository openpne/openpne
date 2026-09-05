<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * The live factor is re-confirmed against the locked row: a parallel disable would otherwise mint
 * codes for a factor that no longer exists. Nothing is revoked, since the factor itself is unchanged
 * (docs/internals/security.md, "Member two-factor authentication").
 */
class RegenerateMemberRecoveryCodes
{
    use SyncsCallerInstance, VerifiesTotpProof;

    public function __construct(
        private readonly GenerateNewRecoveryCodes $generate,
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function __invoke(Member $viewer, string $code): void
    {
        $fresh = DB::transaction(function () use ($viewer, $code): Member {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($fresh->hasEnabledTwoFactorAuthentication(), 403);

            $this->verifyTotpCode($this->provider, $fresh, $code);

            ($this->generate)($fresh);

            // Regenerating proves current authenticator possession, so a lost-factor reset link is moot.
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }
}
