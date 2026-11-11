<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** Starting set-up always asks, even inside the set-up window the request itself opens. */
class MfaManagementRequest extends FormRequest
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
        return $this->reauthRules();
    }

    protected function demandsReauth(): bool
    {
        return true;
    }

    protected function asksSecondFactor(): bool
    {
        return false;
    }
}
