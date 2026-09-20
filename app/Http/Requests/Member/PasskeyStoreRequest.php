<?php

namespace App\Http\Requests\Member;

use App\Features\Member\PasskeyReauth;
use App\Models\Member;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

class PasskeyStoreRequest extends PasskeyRegistrationRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member && PasskeyReauth::isFresh($this->session());
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__('Some time has passed since you confirmed your password. Please confirm it again.'));
    }
}
