<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The registration email-entry submission. Deliberately no `unique` rule: an already-registered
 * address must look identical to a fresh one (enumeration-safety lives in IssueRegistrationToken,
 * which no-ops for a known member). Email normalization is also the action's job.
 */
class RegisterEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
