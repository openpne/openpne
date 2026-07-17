<?php

namespace App\Http\Requests\Member;

use App\Features\Member\MfaSetupReauth;

/**
 * Confirming set-up proves authenticator possession with a TOTP code. The password is only
 * demanded when the enable step's re-auth window has lapsed (a pending set-up left behind and
 * picked up later) — in the normal sitting the member typed it moments ago at enable.
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

    public function requiresPassword(): bool
    {
        return ! MfaSetupReauth::isFresh($this->session());
    }
}
