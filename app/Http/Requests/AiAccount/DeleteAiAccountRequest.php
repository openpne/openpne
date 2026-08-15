<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting an AI account runs the same WithdrawMember a person's own withdrawal does, and takes the
 * account's tokens, group seats and notifications with it — so it re-authenticates the way
 * WithdrawalRequest does, and an unlocked screen is not enough to spend one.
 *
 * Ownership is deliberately not tested here: the route's can:manageAiAccount,member runs first, so
 * a wrong password against a stranger's account id 404s before this validates. The other order
 * would answer that id differently from an unused one, which is an existence oracle.
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
