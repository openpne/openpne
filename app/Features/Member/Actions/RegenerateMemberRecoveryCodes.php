<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Replace a member's unused recovery codes with a fresh set, gated by a current TOTP code. The
 * factor's live state is re-confirmed against the row locked FOR UPDATE: a parallel disable would
 * otherwise let this mint codes for a factor that no longer exists (orphans). No session revocation —
 * the TOTP factor itself is unchanged (admin parity).
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

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }
}
