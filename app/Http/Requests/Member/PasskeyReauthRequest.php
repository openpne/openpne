<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Validation\Rule;

/**
 * Only the second-factor proof's presence is validated here: the Action verifies its value after the
 * password has passed, so a wrong password never spends a recovery code or marks a TOTP code used.
 */
class PasskeyReauthRequest extends MfaManagementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // requiredIf is implicit, so `nullable` does not defeat it and only lets the field pass when not demanded.
            'code' => [
                Rule::requiredIf(fn (): bool => $this->requiresSecondFactor() && ! $this->filled('recovery_code')),
                'nullable',
                'string',
            ],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    /** The snapshot the Action re-checks under the member row lock. */
    public function requiresSecondFactor(): bool
    {
        $viewer = $this->user();
        assert($viewer instanceof Member);

        return $viewer->hasEnabledTwoFactorAuthentication();
    }
}
