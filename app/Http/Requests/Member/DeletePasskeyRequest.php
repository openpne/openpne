<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class DeletePasskeyRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::PasskeyRemove;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reauthRules();
    }
}
