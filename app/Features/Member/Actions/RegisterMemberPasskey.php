<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class RegisterMemberPasskey
{
    /** The `passkeys.credential_id` column width; webauthn-lib itself allows ids up to 1023 bytes. */
    private const CREDENTIAL_ID_MAX = 512;

    public function __construct(private readonly StorePasskey $store) {}

    public function __invoke(Member $viewer, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
    {
        $this->refuseOverlongCredentialId($credential);

        return DB::transaction(function () use ($viewer, $name, $credential, $options): Passkey {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            // An AI account has no session to reach here; the refusal is defense in depth, so a
            // credential-less row never gains a credential.
            abort_if($fresh->isAiAccount(), 403);

            return ($this->store)($fresh, $name, $credential, $options);
        });
    }

    /** The stored id is the attested one inside the attestation object, not the JSON `rawId`. */
    private function refuseOverlongCredentialId(PublicKeyCredential $credential): void
    {
        $response = $credential->response;
        $attested = $response instanceof AuthenticatorAttestationResponse
            ? $response->attestationObject->authData->attestedCredentialData?->credentialId
            : null;

        if ($attested !== null && strlen(Base64UrlSafe::encodeUnpadded($attested)) > self::CREDENTIAL_ID_MAX) {
            throw ValidationException::withMessages(['credential' => __('Unable to register this passkey.')]);
        }
    }
}
