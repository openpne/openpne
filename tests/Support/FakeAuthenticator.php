<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * A test-only WebAuthn authenticator: one ES256 credential, attestation `none`, discoverable. Flags
 * and the signature counter are settable so a test can produce exactly the assertion it needs.
 */
final class FakeAuthenticator
{
    public const AAGUID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    private OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private ?string $userHandle = null;

    public int $counter = 0;

    public bool $userVerified = true;

    public bool $backupEligible = true;

    public bool $backedUp = true;

    public function __construct(private readonly string $origin, int $credentialIdBytes = 32)
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        assert($key !== false);
        $this->key = $key;
        $this->credentialId = random_bytes($credentialIdBytes);
    }

    public static function forApp(int $credentialIdBytes = 32): self
    {
        return new self((string) config('app.url'), $credentialIdBytes);
    }

    public function credentialId(): string
    {
        return Base64UrlSafe::encodeUnpadded($this->credentialId);
    }

    /**
     * @param  array<string, mixed>  $options  the `options` array the registration endpoint returned
     * @return array<string, mixed> the `credential` the browser would post back
     */
    public function attest(array $options, string $type = 'webauthn.create'): array
    {
        $this->userHandle = Base64UrlSafe::decodeNoPadding($options['user']['id']);

        $clientDataJson = $this->clientDataJson($type, $options['challenge']);
        $authData = $this->authData($options['rp']['id'], attested: true);

        $attestationObject = (string) MapObject::create([
            MapItem::create(TextStringObject::create('fmt'), TextStringObject::create('none')),
            MapItem::create(TextStringObject::create('attStmt'), MapObject::create([])),
            MapItem::create(TextStringObject::create('authData'), ByteStringObject::create($authData)),
        ]);

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => [],
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options  the `options` array the login endpoint returned
     * @return array<string, mixed>
     */
    public function assert(array $options, string $type = 'webauthn.get'): array
    {
        $clientDataJson = $this->clientDataJson($type, $options['challenge']);
        $authData = $this->authData($options['rpId'], attested: false);

        $signed = openssl_sign($authData.hash('sha256', $clientDataJson, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        assert($signed);

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => [],
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => $this->userHandle === null ? null : Base64UrlSafe::encodeUnpadded($this->userHandle),
            ],
        ];
    }

    private function clientDataJson(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function authData(string $rpId, bool $attested): string
    {
        $flags = 0x01;
        if ($this->userVerified) {
            $flags |= 0x04;
        }
        if ($this->backupEligible) {
            $flags |= 0x08;
        }
        if ($this->backedUp) {
            $flags |= 0x10;
        }
        if ($attested) {
            $flags |= 0x40;
        }

        $authData = hash('sha256', $rpId, true).chr($flags).pack('N', $this->counter);

        if ($attested) {
            $authData .= self::AAGUID.pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        }

        return $authData;
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        assert(is_array($details));

        return (string) MapObject::create([
            MapItem::create(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)),
            MapItem::create(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)),
            MapItem::create(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)),
            MapItem::create(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))),
            MapItem::create(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))),
        ]);
    }
}
