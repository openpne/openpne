<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class DeletePasskeyRequest extends FormRequest
{
    use RequiresReauth;

    /** A passkey that is not the member's is a 404 before any proof is read, so a stale form spends no code. */
    public function authorize(): bool
    {
        $viewer = $this->user();
        if (! $viewer instanceof Member) {
            return false;
        }

        abort_unless($viewer->passkeys()->whereKey($this->route('id'))->exists(), 404);

        return true;
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
