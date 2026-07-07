<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Two-factor management re-authenticates inline with the account password (current_password:member
 * — the guard is `member`, not the default), so a walked-up unattended session cannot use these
 * endpoints. Subclasses relax WHEN the password is demanded (a fresh set-up re-auth window, an
 * inert pending set-up), never how it is verified: whenever the field is present it must be the
 * account password.
 */
class MfaManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    protected function prepareForValidation(): void
    {
        // An empty field is "not provided", not a wrong password: HTML forms and shared client
        // form state submit it even when the UI does not show it (ConvertEmptyStringsToNull has
        // already turned '' into null here). Dropping it lets requiredIf decide — a
        // demanded-but-empty password fails as "required" (accurate), and an undemanded empty one
        // no longer shadows the real error (e.g. an invalid TOTP code) with a message the page
        // has no field to show it under.
        if (in_array($this->input('current_password'), [null, ''], true)) {
            $this->request->remove('current_password');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->requiresPassword()),
                'current_password:member',
            ],
        ];
    }

    protected function requiresPassword(): bool
    {
        return true;
    }
}
