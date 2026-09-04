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
        // An empty field is "not provided", not a wrong password: the form submits it even when the
        // UI shows no field, and dropping it lets requiredIf decide rather than raising an error the
        // page has nowhere to show.
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

    /**
     * Whether this request demanded (and thus verified) the account password. Public so the
     * controller can fail-closed: it is the snapshot of the factor state the FormRequest validated
     * against, which a concurrent change may have since invalidated (see MemberMfaController).
     */
    public function requiresPassword(): bool
    {
        return true;
    }
}
