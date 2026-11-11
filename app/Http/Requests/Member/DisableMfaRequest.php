<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** Cancelling a pending set-up asks for nothing, since the pending secret gates nothing. */
class DisableMfaRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::MfaDisable;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reauthRules();
    }

    protected function demandsReauth(): bool
    {
        return $this->requiresSecondFactor();
    }

    protected function verifiesReauthInAction(): bool
    {
        return true;
    }
}
