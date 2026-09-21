<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RewordsUnreadableCredential;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

/** Bound in place of the package request, since the package controller's signature cannot be narrowed to it. */
class PasskeyLoginRequest extends PasskeyVerificationRequest
{
    use RewordsUnreadableCredential;
}
