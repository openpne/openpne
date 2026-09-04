<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Validation\Rule;

/**
 * Disabling a live factor demands the password and a second-factor proof; cancelling a pending
 * set-up demands neither, since the pending secret gates nothing. Only the proof's presence is
 * validated here: the Action verifies its value after the password has passed, so a wrong password
 * never spends a recovery code or marks a TOTP code used.
 */
class DisableMfaRequest extends MfaManagementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // requiredIf is implicit, so `nullable` does not defeat it and only lets the field pass when not demanded.
            'code' => [
                Rule::requiredIf(fn (): bool => $this->requiresPassword() && ! $this->filled('recovery_code')),
                'nullable',
                'string',
            ],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    public function requiresPassword(): bool
    {
        $viewer = $this->user();
        assert($viewer instanceof Member);

        return $viewer->hasEnabledTwoFactorAuthentication();
    }
}
