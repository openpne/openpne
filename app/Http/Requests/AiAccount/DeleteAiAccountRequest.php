<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting an AI account runs the same WithdrawMember a person's own withdrawal does, so it
 * re-authenticates with the password like WithdrawalRequest. Ownership is deliberately not tested
 * here: the route's `can:manageAiAccount,member` runs first, so a wrong password against a stranger's
 * id 404s rather than answering differently from an unused id.
 */
class DeleteAiAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'current_password:member']];
    }
}
