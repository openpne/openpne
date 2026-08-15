<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Features\AiAccount\AiTokenReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Handing out an AI account's token, and taking one back, re-authenticate with the owner's account
 * password (`current_password:member` — the guard is `member`, not the default), so a walked-up
 * unattended session cannot mint a credential that outlives the sitting. Verified once per sitting
 * rather than per request ({@see AiTokenReauth}), the same shape the two-factor set-up flow uses;
 * whenever the field is present it must be the account password, whatever the window says.
 *
 * Ownership is settled before any of this: both token routes carry `can:manageAiAccount,member`, so
 * an id that is not one of the viewer's own AI accounts is refused before this request is validated.
 * A password error reaching such an id would answer differently for an account that exists than for
 * one that does not, which is the whole of what that 404 hides.
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
