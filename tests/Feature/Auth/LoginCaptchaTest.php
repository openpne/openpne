<?php

namespace Tests\Feature\Auth;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * After repeated failures from one IP the login form requires a CAPTCHA — a soft escalation, never a
 * lockout. CAPTCHA is off in the test env by default (phpunit.xml), so this turns it on (with a cheap
 * proof-of-work and a low threshold) and drives the failure counter. The widget's browser-side solving
 * is reproduced with the library itself, the same serialization the widget posts.
 */
class LoginCaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('openpne.captcha.enabled', true);
        config()->set('openpne.captcha.altcha.cost', 100); // keep the proof-of-work cheap in tests
        config()->set('openpne.login.captcha_after_failures', 2);
    }

    public function test_login_needs_no_captcha_below_the_threshold(): void
    {
        $member = Member::factory()->create();

        $this->get('/login')->assertDontSee('altcha-widget', false);
        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($member);
    }

    public function test_repeated_failures_require_a_captcha(): void
    {
        $member = Member::factory()->create();
        $this->failLogin($member, 2);

        // The form now carries the widget, and a submit without a solution is rejected — even with the
        // right password, since the challenge is checked before the credentials.
        $this->get('/login')->assertSee('altcha-widget', false);
        $this->from('/login')->post('/login', ['email' => $member->email, 'password' => 'password'])
            ->assertSessionHasErrors('altcha');
        $this->assertGuest();
    }

    public function test_a_valid_captcha_lets_a_correct_password_through(): void
    {
        $member = Member::factory()->create();
        $this->failLogin($member, 2);

        $this->post('/login', ['email' => $member->email, 'password' => 'password', 'altcha' => $this->solvedPayload()])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($member);
    }

    public function test_a_successful_login_clears_the_failure_counter(): void
    {
        $member = Member::factory()->create();
        $this->failLogin($member, 2);

        $this->post('/login', ['email' => $member->email, 'password' => 'password', 'altcha' => $this->solvedPayload()]);
        $this->post('/logout');

        // The counter was cleared on success, so the next visit is back below the threshold.
        $this->get('/login')->assertDontSee('altcha-widget', false);
    }

    public function test_no_captcha_is_required_when_the_feature_is_disabled(): void
    {
        config()->set('openpne.captcha.enabled', false);
        $member = Member::factory()->create();
        $this->failLogin($member, 2);

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($member);
    }

    private function failLogin(Member $member, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->post('/login', ['email' => $member->email, 'password' => 'wrong-password']);
        }
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
