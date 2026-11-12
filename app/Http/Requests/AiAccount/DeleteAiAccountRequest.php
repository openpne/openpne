<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ownership is deliberately not tested here: the route's `can:manageAiAccount,member` runs first, so
 * a wrong password against a stranger's id 404s rather than answering differently from an unused id.
 */
class DeleteAiAccountRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::AiAccountDelete;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reauthRules();
    }
}
