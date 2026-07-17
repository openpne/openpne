<?php

namespace App\Http\Requests\Member;

/**
 * Regenerating recovery codes rotates a credential, so it re-authenticates with the account
 * password AND a current TOTP code. Only the code's PRESENCE is validated here; its value is
 * verified in the controller, after the password rule has passed — so a wrong password never
 * reaches (and never marks used) Fortify's TOTP replay cache.
 */
class RegenerateMfaRequest extends MfaManagementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'code' => ['required', 'string'],
        ];
    }
}
