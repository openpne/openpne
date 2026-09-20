<?php

namespace App\Features\Auth;

use App\Support\SecurityLog;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController as VendorController;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Webauthn\Exception\WebauthnException;

/**
 * The package logs the guard in without firing `Failed`, so a refused sign-in is recorded here the
 * way `LogFailedLogin` records a wrong password; the gate marks its own refusal to avoid a double row.
 */
class PasskeyLoginController extends VendorController
{
    public const REFUSED_BY_GATE = 'passkey.refused_by_gate';

    public function store(PasskeyVerificationRequest $request, VerifyPasskey $verify): PasskeyLoginResponse
    {
        try {
            return parent::store($request, $verify);
        } catch (ValidationException|WebauthnException $e) {
            if (! $request->attributes->get(self::REFUSED_BY_GATE, false)) {
                SecurityLog::event('passkey.failed', ['guard' => 'member']);
            }

            throw $e;
        }
    }
}
