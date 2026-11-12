<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `code` here is the pending factor's own code, proving the authenticator was set up; the password
 * is asked again only once the set-up window has lapsed.
 */
class ConfirmMfaRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::MfaSetup;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string']] + $this->reauthRules();
    }

    protected function asksSecondFactor(): bool
    {
        return false;
    }
}
