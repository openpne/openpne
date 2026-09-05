<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

class ConfirmMemberMfa
{
    use SyncsCallerInstance;

    public function __construct(private readonly ConfirmTwoFactorAuthentication $confirm) {}

    public function __invoke(Member $viewer, string $code, string $exceptSessionId): void
    {
        $fresh = DB::transaction(function () use ($viewer, $code, $exceptSessionId): Member {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();
            abort_if($fresh->hasEnabledTwoFactorAuthentication(), 403);
            abort_if(blank($fresh->two_factor_secret), 403);

            // Fail closed if a concurrent enable rotated the pending secret: the compared ciphertext
            // is the stored one, stable per row.
            if ($viewer->two_factor_secret !== $fresh->two_factor_secret) {
                throw ValidationException::withMessages([
                    'code' => __('Your two-factor settings changed while this page was open. Please try again.'),
                ]);
            }

            ($this->confirm)($fresh, $code);
            SessionRevocation::revokeMember($fresh, $exceptSessionId);

            // A reset link must never survive a change in the factor's lifecycle
            // (docs/internals/security.md, "Member two-factor authentication").
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }
}
