<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;

class PasskeyCredentialLengthTest extends TestCase
{
    use RefreshDatabase;

    public const MAX = 16384;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function registeredPasskey(Member $member): FakeAuthenticator
    {
        $authenticator = FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($member);
        $credential = WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class);
        app(StorePasskey::class)($member, 'phone', $credential, $options);

        return $authenticator;
    }

    /**
     * Pads the client data, which the signature covers, to exactly the cap once encoded.
     *
     * @param  Closure(): array<string, mixed>  $credential
     * @return array<string, mixed>
     */
    private function atTheCap(FakeAuthenticator $authenticator, Closure $credential): array
    {
        $authenticator->clientDataExtras = ['padding' => ''];
        $bytes = strlen(Base64UrlSafe::decodeNoPadding($credential()['response']['clientDataJSON']));
        $authenticator->clientDataExtras = ['padding' => str_repeat('x', intdiv(self::MAX * 3, 4) - $bytes)];

        $posted = $credential();
        $this->assertSame(self::MAX, strlen($posted['response']['clientDataJSON']));

        return $posted;
    }

    /**
     * @param  array<string, mixed>  $credential
     * @return array<string, mixed>
     */
    private function withField(array $credential, string $field, int $length): array
    {
        data_set($credential, substr($field, strlen('credential.')), str_repeat('A', $length));

        return $credential;
    }

    private function assertRefusedBeforeDecoding(TestResponse $response, string $field): void
    {
        // The deserialiser's own refusal lands on `credential`, so its absence shows the decode never ran.
        $response->assertUnprocessable()
            ->assertJsonValidationErrors([$field => __('The passkey could not be read.')])
            ->assertJsonMissingValidationErrors('credential');
    }

    /** @return array<string, array{string}> */
    public static function decodedFields(): array
    {
        return array_map(fn (string $field): array => [$field], array_combine($fields = [
            'credential.id',
            'credential.rawId',
            'credential.response.clientDataJSON',
            'credential.response.authenticatorData',
            'credential.response.signature',
            'credential.response.userHandle',
            'credential.response.attestationObject',
        ], $fields));
    }

    #[DataProvider('decodedFields')]
    public function test_a_guest_sign_in_field_past_the_cap_is_refused_before_decoding(string $field): void
    {
        $options = $this->getJson('/passkeys/login/options')->assertOk()->json('options');
        $assertion = FakeAuthenticator::forApp()->assert($options);

        $this->postJson('/passkeys/login', ['credential' => $this->withField($assertion, $field, self::MAX)])
            ->assertJsonMissingValidationErrors($field);
        $this->assertRefusedBeforeDecoding($this->postJson('/passkeys/login', ['credential' => $this->withField($assertion, $field, self::MAX + 1)]), $field);
    }

    public function test_a_sign_in_with_a_field_at_the_cap_still_signs_the_member_in(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $options = $this->getJson('/passkeys/login/options')->assertOk()->json('options');

        $this->postJson('/passkeys/login', ['credential' => $this->atTheCap($authenticator, fn (): array => $authenticator->assert($options))])
            ->assertOk();
        $this->assertAuthenticatedAs($member);
    }

    public function test_a_reauth_assertion_past_the_cap_is_refused_before_decoding(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $this->actingAs($member, 'member');
        $options = $this->getJson('/member/reauth/passkey/options?purpose=withdrawal')->assertOk()->json('options');

        $field = 'credential.response.authenticatorData';
        $this->assertRefusedBeforeDecoding($this->postJson('/member/reauth/passkey', ['credential' => $this->withField($authenticator->assert($options), $field, self::MAX + 1)]), $field);
    }

    public function test_a_registration_past_the_cap_is_refused_before_decoding_and_one_at_it_is_stored(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member)->post('/member/config/passkeys/reauth', ['current_password' => 'password'])->assertSessionHasNoErrors();
        $authenticator = FakeAuthenticator::forApp();
        $options = $this->getJson('/member/config/passkeys/options')->assertOk()->json('options');

        $field = 'credential.response.attestationObject';
        $this->assertRefusedBeforeDecoding($this->postJson('/member/config/passkeys', ['credential' => $this->withField($authenticator->attest($options), $field, self::MAX + 1)]), $field);

        $this->postJson('/member/config/passkeys', ['credential' => $this->atTheCap($authenticator, fn (): array => $authenticator->attest($options))])
            ->assertOk();
        $this->assertSame(1, $member->passkeys()->count());
    }

    public function test_the_members_a_browser_adds_beyond_the_capped_fields_are_never_decoded(): void
    {
        $authenticator = FakeAuthenticator::forApp();
        $registration = $authenticator->attest(WebAuthn::toBrowserArray(app(GenerateRegistrationOptions::class)(Member::factory()->create())));
        $assertion = $authenticator->assert(WebAuthn::toBrowserArray(app(GenerateVerificationOptions::class)()));

        // Not base64, so a member the package decoded would throw.
        $undecodable = '!';
        foreach ([$registration, $assertion] as $credential) {
            $credential['authenticatorAttachment'] = $undecodable;
            $credential['clientExtensionResults'] = ['credProps' => $undecodable, 'prf' => ['results' => ['first' => $undecodable]], 'largeBlob' => ['blob' => $undecodable]];
            $credential['response'] = array_replace($credential['response'], ['publicKey' => $undecodable, 'publicKeyAlgorithm' => $undecodable, 'transports' => [$undecodable]]);

            $this->assertInstanceOf(PublicKeyCredential::class, WebAuthn::fromJson(json_encode($credential), PublicKeyCredential::class));
        }
    }
}
