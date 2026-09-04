<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Features\AiAccount\AiTokenReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Minting or revoking a token re-authenticates with the owner's password (`current_password:member`,
 * since the guard is not the default), verified once per sitting through AiTokenReauth. Ownership is
 * settled first by the route's `can:manageAiAccount,member`, so a password error never answers
 * differently for an AI account that exists than for one that does not.
 */
class AiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    protected function prepareForValidation(): void
    {
        // An empty field is "not provided", not a wrong password: the form submits it even inside
        // the window, when the UI shows no password field at all (MfaManagementRequest precedent;
        // ConvertEmptyStringsToNull has already turned '' into null by here).
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
            // Absent on the revoke POST, and absent from an unchecked box.
            'read_only' => ['sometimes', 'boolean'],
        ];
    }

    /** Whether this request demanded (and thus verified) the password — what the controller stamps on. */
    public function requiresPassword(): bool
    {
        return ! AiTokenReauth::isFresh($this->session());
    }
}
