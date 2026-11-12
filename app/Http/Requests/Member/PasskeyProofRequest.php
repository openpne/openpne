<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Features\Member\ReauthPasskeyController;
use App\Http\Requests\Concerns\RewordsUnreadableCredential;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeyProofRequest extends PasskeyVerificationRequest
{
    use RewordsUnreadableCredential;

    /**
     * The purpose comes from what the options request stored, never from this request's body. A slot
     * older than the controller's replay marker is refused, since nothing would stop its second use.
     *
     * @return array{PublicKeyCredentialRequestOptions, ReauthPurpose}
     */
    public function ceremony(): array
    {
        $slot = $this->session()->pull(ReauthPasskeyController::OPTIONS_SLOT);
        $purpose = is_array($slot) && is_string($slot['purpose'] ?? null) ? ReauthPurpose::tryFrom($slot['purpose']) : null;

        $fresh = is_array($slot) && is_int($slot['at'] ?? null) && $slot['at'] <= now()->getTimestamp() && now()->getTimestamp() - $slot['at'] <= ReauthPasskeyController::CEREMONY_SECONDS;

        if ($purpose === null || ! $fresh || ! is_string($slot['options'] ?? null)) {
            throw ValidationException::withMessages([
                'credential' => __('Passkey verification session expired. Please try again.'),
            ]);
        }

        return [WebAuthn::fromJson($slot['options'], PublicKeyCredentialRequestOptions::class), $purpose];
    }
}
