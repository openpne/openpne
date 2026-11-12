<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The TOTP code is the operation's own demand (current authenticator possession), so a recovery
 * code never stands in for it.
 */
class RegenerateMfaRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::MfaRecoveryCodes;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string']] + $this->reauthRules();
    }

    protected function allowsRecoveryCode(): bool
    {
        return false;
    }

    protected function verifiesReauthInAction(): bool
    {
        return true;
    }
}
