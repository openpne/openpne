<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service account withdrawal. Re-authenticates with the current password (current_password:member)
 * and requires an explicit confirmation checkbox. The primary member (id 1) is never withdrawable —
 * rejected here so it is a 403, not the RuntimeException (500) WithdrawMember would throw.
 */
class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Member && (int) $user->getKey() !== 1;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'current_password:member'],
            'confirm' => ['accepted'],
        ];
    }
}
