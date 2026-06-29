<?php

namespace App\Http\Requests\Member;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * In-session password change. Re-authenticates with the current password (current_password:member —
 * the guard is `member`, not the default) so a walked-up unattended session can't change it, and
 * reuses the shared password rules so register / reset / change stay one policy.
 */
class UpdatePasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:member'],
            'password' => $this->passwordRules(),
        ];
    }
}
