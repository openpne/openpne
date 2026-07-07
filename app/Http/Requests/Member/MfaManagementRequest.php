<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Every two-factor management action re-authenticates inline with the account password
 * (current_password:member — the guard is `member`, not the default), so a walked-up unattended
 * session can neither enable, disable, nor read fresh recovery codes.
 */
class MfaManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:member'],
        ];
    }
}
