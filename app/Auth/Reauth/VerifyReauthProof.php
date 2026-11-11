<?php

namespace App\Auth\Reauth;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

class VerifyReauthProof
{
    use VerifiesReauthProof;

    public function __invoke(Member $viewer, ReauthProof $proof): void
    {
        $fresh = DB::transaction(function () use ($viewer, $proof): Member {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            $this->verifyReauthProof($fresh, $proof);

            return $fresh;
        });

        // Only the spent recovery code is carried over: an Action that compares the caller's instance
        // against its own locked row must still see what the request began with.
        $viewer->setRawAttributes(
            ['two_factor_recovery_codes' => $fresh->getRawOriginal('two_factor_recovery_codes')] + $viewer->getAttributes(),
            true,
        );
    }
}
