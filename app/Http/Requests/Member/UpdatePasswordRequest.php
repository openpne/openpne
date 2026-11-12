<?php

namespace App\Http\Requests\Member;

use App\Actions\Fortify\PasswordValidationRules;
use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** Shares the password rules with registration and reset, so the three stay one policy. */
class UpdatePasswordRequest extends FormRequest
{
    use PasswordValidationRules, RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::PasswordChange;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => $this->passwordRules()] + $this->reauthRules();
    }
}
