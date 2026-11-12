<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ownership is settled first by the route's `can:manageAiAccount,member`, so a step-up error never
 * answers differently for an AI account that exists than for one that does not.
 */
class AiTokenRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::AiToken;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Absent on the revoke POST, and absent from an unchecked box.
        return ['read_only' => ['sometimes', 'boolean']] + $this->reauthRules();
    }
}
