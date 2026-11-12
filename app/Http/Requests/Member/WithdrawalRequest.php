<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** The primary member (id 1) is refused in authorize() so it is a 403, not the RuntimeException WithdrawMember would throw. */
class WithdrawalRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Member && (int) $user->getKey() !== 1;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::Withdrawal;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => ['accepted']] + $this->reauthRules();
    }
}
