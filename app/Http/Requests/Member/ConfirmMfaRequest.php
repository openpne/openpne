<?php

namespace App\Http\Requests\Member;

/**
 * Confirming set-up proves authenticator possession with a TOTP code, on top of the
 * password re-auth every management action carries.
 */
class ConfirmMfaRequest extends MfaManagementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'code' => ['required', 'string'],
        ];
    }
}
