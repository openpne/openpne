<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\Aaguids;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AttestedCredentialData;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class RegisterMemberPasskey
{
    /** The `passkeys.credential_id` column width; webauthn-lib itself allows ids up to 1023 bytes. */
    private const CREDENTIAL_ID_MAX = 512;

    public function __construct(private readonly StorePasskey $store) {}

    public function __invoke(Member $viewer, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
    {
        $this->refuseOverlongCredentialId($credential);
        $name = $this->deriveName($credential);

        return DB::transaction(function () use ($viewer, $name, $credential, $options): Passkey {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            // An AI account has no session to reach here; the refusal is defense in depth, so a
            // credential-less row never gains a credential.
            abort_if($fresh->isAiAccount(), 403);

            return ($this->store)($fresh, $name, $credential, $options);
        });
    }

    /**
     * Where the passkey is kept, which is what the member recognises it by; empty when the
     * authenticator withholds its AAGUID or the bundled table does not know it, and the screen
     * calls that one "passkey" (docs/internals/security.md, "Member passkeys").
     */
    private function deriveName(PublicKeyCredential $credential): string
    {
        $aaguid = $this->attestedCredentialData($credential)?->aaguid;

        return $aaguid === null ? '' : (Aaguids::labelFor((string) $aaguid) ?? '');
    }

    /** The stored id is the attested one inside the attestation object, not the JSON `rawId`. */
    private function refuseOverlongCredentialId(PublicKeyCredential $credential): void
    {
        $attested = $this->attestedCredentialData($credential)?->credentialId;

        if ($attested !== null && strlen(Base64UrlSafe::encodeUnpadded($attested)) > self::CREDENTIAL_ID_MAX) {
            throw ValidationException::withMessages(['credential' => __('Unable to register this passkey.')]);
        }
    }

    private function attestedCredentialData(PublicKeyCredential $credential): ?AttestedCredentialData
    {
        $response = $credential->response;

        return $response instanceof AuthenticatorAttestationResponse
            ? $response->attestationObject->authData->attestedCredentialData
            : null;
    }
}
