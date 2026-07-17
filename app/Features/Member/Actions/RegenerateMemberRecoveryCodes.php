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
 * the TOTP factor itself is unchanged (admin parity). The code is verified against the fresh row, but
 * the codes are generated on the authenticated instance so the session's flash state stays consistent.
 */
class RegenerateMemberRecoveryCodes
{
    use VerifiesTotpProof;

    public function __construct(
        private readonly GenerateNewRecoveryCodes $generate,
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function __invoke(Member $viewer, string $code): void
    {
        DB::transaction(function () use ($viewer, $code): void {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($fresh->hasEnabledTwoFactorAuthentication(), 403);

            $this->verifyTotpCode($this->provider, $fresh, $code);

            ($this->generate)($viewer);
        });
    }
}
