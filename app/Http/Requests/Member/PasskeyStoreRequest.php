<?php

namespace App\Http\Requests\Member;

use App\Features\Member\PasskeyReauth;
use App\Models\Member;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

class PasskeyStoreRequest extends PasskeyRegistrationRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member && PasskeyReauth::isFresh($this->session());
    }
}
