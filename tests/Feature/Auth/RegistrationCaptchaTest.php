<?php

namespace Tests\Feature\Auth;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * CAPTCHA is disabled in the test env by default (phpunit.xml), so this turns it on and exercises the
 * real ALTCHA verify path. The widget's browser-side solving is not reproducible in CI, so a valid
 * submission is produced here with the library itself — the same serialization the widget posts.
 */
class RegistrationCaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('openpne.captcha.enabled', true);
        config()->set('openpne.captcha.altcha.cost', 100); // keep the proof-of-work cheap in tests
    }

    public function test_the_challenge_endpoint_serves_a_signed_challenge(): void
    {
        $this->getJson('/altcha/challenge')
            ->assertOk()
            ->assertJsonStructure(['parameters' => ['algorithm', 'salt', 'keyPrefix', 'nonce'], 'signature']);
    }

    public function test_registration_without_a_solution_is_rejected(): void
    {
        Notification::fake();
        $this->armForm();

        $this->from('/register')->post('/register', ['email' => 'newcomer@example.com'])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('altcha');

        $this->assertDatabaseCount('registration_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_registration_with_a_valid_solution_is_accepted(): void
    {
        Notification::fake();
        $payload = $this->solvedPayload();
        $this->armForm();

        $this->post('/register', ['email' => 'newcomer@example.com', 'altcha' => $payload])
            ->assertRedirect(route('register.sent'));

        $this->assertDatabaseHas('registration_tokens', ['email' => 'newcomer@example.com']);
    }

    private function armForm(): void
    {
        $this->get('/register');
        $this->travel(3)->seconds();
    }

    /** A real solution to a freshly-issued challenge, serialized exactly as the widget would post it. */
    private function solvedPayload(): string
    {
        $json = $this->getJson('/altcha/challenge')->json();
        $challenge = new Challenge(ChallengeParameters::fromArray($json['parameters']), $json['signature']);
        $solution = (new Altcha)->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));

        return (new Payload($challenge, $solution))->toBase64();
    }
}
