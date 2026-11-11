<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** This endpoint is the ask itself, so it demands the proof even while its window is still open. */
class PasskeyReauthRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::PasskeyAdd;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reauthRules();
    }

    protected function demandsReauth(): bool
    {
        return true;
    }
}
