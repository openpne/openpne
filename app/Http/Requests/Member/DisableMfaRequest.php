<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Validation\Rule;

/**
 * Disabling a confirmed factor weakens the account, so it always re-authenticates with the
 * account password AND a second-factor proof (a current TOTP code, or an unused recovery code).
 * Cancelling a pending set-up does not: the pending secret gates nothing, so demanding either
 * would only punish the member for abandoning a wizard.
 *
 * Only the proof's PRESENCE is validated here; its value is verified by the Action, after the
 * password rule has passed — so a wrong password never spends a recovery code or marks a TOTP
 * code used (see Actions\DisableMemberMfa).
 */
class DisableMfaRequest extends MfaManagementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // A code is demanded only when the factor is live and no recovery code was offered
            // instead; a pending cancel demands neither. requiredIf is implicit, so 'nullable'
            // does not defeat it — it only lets the field pass when it is not required.
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
