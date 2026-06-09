<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\Actions\CompleteRegistration;
use App\Models\Member;
use App\Models\RegistrationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The registration completion half (OpenPNE 3 member/registerInput): the token-gated account form
 * and the create-and-login it submits to. The token's address is authoritative throughout; a spent,
 * expired, or unknown token cannot create an account.
 */
class RegistrationCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'sufficiently-long-pw';

    /** Issue a live pending registration and return the raw token its link would carry. */
    private function issueToken(string $email = 'newcomer@example.com'): string
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => $email, 'token' => hash('sha256', $raw), 'created_at' => now()]);

        return $raw;
    }

    /** @return array<string, string> */
    private function validForm(): array
    {
        return ['name' => 'Newcomer', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_the_form_renders_the_classic_surface_with_the_token_address(): void
    {
        $token = $this->issueToken('newcomer@example.com');

        $this->get("/register/{$token}")
            ->assertOk()
            ->assertSee('id="page_member_registerInput"', false)
            ->assertSee('insecure_page', false)
            ->assertSee('newcomer@example.com')
            ->assertSee('name="name"', false);
    }

    public function test_the_form_renders_the_modern_surface_when_selected(): void
    {
        config()->set('openpne.tenant_default_surface', 'modern');
        $token = $this->issueToken('newcomer@example.com');

        $this->get("/register/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/register-complete')
                ->where('email', 'newcomer@example.com')
                ->where('token', $token));
    }

    public function test_an_unknown_token_is_sent_back_to_request_a_new_link(): void
    {
        $this->get('/register/'.Str::random(40))
            ->assertRedirect(route('register'))
            ->assertSessionHas('status');
    }

    public function test_an_expired_token_is_sent_back_to_request_a_new_link(): void
    {
        $raw = Str::random(40);
        RegistrationToken::create([
            'email' => 'stale@example.com',
            'token' => hash('sha256', $raw),
            'created_at' => now()->subMinutes((int) config('openpne.registration.token_ttl_minutes') + 1),
        ]);

        $this->get("/register/{$raw}")->assertRedirect(route('register'));
    }

    public function test_a_malformed_token_404s_at_the_route(): void
    {
        // The route pins the token to its 40-char alnum shape, so a short or punctuated probe never
        // reaches the controller.
        $this->get('/register/short')->assertNotFound();
        $this->get('/register/'.str_repeat('a', 39).'!')->assertNotFound();
    }

    public function test_a_valid_submission_creates_the_member_logs_in_and_consumes_the_token(): void
    {
        $token = $this->issueToken('newcomer@example.com');

        $this->post("/register/{$token}", $this->validForm())
            ->assertRedirect('/');

        $this->assertAuthenticated();
        $member = Member::where('email', 'newcomer@example.com')->first();
        $this->assertNotNull($member);
        $this->assertTrue($this->app['auth']->user()->is($member));
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_the_email_comes_from_the_token_not_the_post_body(): void
    {
        // The address was proven by the mailed link; a posted email must be ignored, or anyone with a
        // token for address A could register address B.
        $token = $this->issueToken('owner@example.com');

        $this->post("/register/{$token}", $this->validForm() + ['email' => 'attacker@example.com'])
            ->assertRedirect('/');

        $this->assertDatabaseHas('members', ['email' => 'owner@example.com']);
        $this->assertDatabaseMissing('members', ['email' => 'attacker@example.com']);
    }

    public function test_a_token_cannot_be_replayed_after_a_successful_registration(): void
    {
        $token = $this->issueToken('newcomer@example.com');

        $this->post("/register/{$token}", $this->validForm())->assertRedirect('/');
        $this->app['auth']->logout();

        // The same token now resolves to nothing — single-use.
        $this->post("/register/{$token}", ['name' => 'Second', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('register'));

        $this->assertSame(1, Member::where('email', 'newcomer@example.com')->count());
    }

    public function test_a_validation_failure_keeps_the_token_and_creates_no_member(): void
    {
        $token = $this->issueToken('newcomer@example.com');

        $this->from("/register/{$token}")
            ->post("/register/{$token}", ['name' => '', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect("/register/{$token}")
            ->assertSessionHasErrors('name');

        $this->assertGuest();
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('registration_tokens', 1);
    }

    public function test_a_password_mismatch_is_rejected(): void
    {
        $token = $this->issueToken('newcomer@example.com');

        $this->from("/register/{$token}")
            ->post("/register/{$token}", ['name' => 'Newcomer', 'password' => self::PASSWORD, 'password_confirmation' => 'different'])
            ->assertRedirect("/register/{$token}")
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('members', 0);
    }

    public function test_an_address_claimed_since_issuance_is_sent_to_sign_in(): void
    {
        // A member took the address between the token being issued and the form being completed
        // (admin creation, or an earlier completion). Nothing to create — go log in, token consumed.
        $token = $this->issueToken('taken@example.com');
        Member::factory()->create(['email' => 'taken@example.com']);

        $this->post("/register/{$token}", $this->validForm())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
        $this->assertSame(1, Member::where('email', 'taken@example.com')->count());
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_an_address_claimed_during_validation_is_sent_to_sign_in(): void
    {
        // The up-front check passes (no member yet), but the address is claimed before the create's
        // unique rule runs, so creation fails on `email` (a ValidationException, not a QueryException).
        // The form has no email field to show it, so the dead token is consumed and the user is sent
        // to sign in — the same outcome as the other races.
        $token = $this->issueToken('newcomer@example.com');

        $this->mock(CompleteRegistration::class)
            ->shouldReceive('__invoke')
            ->andThrow(ValidationException::withMessages(['email' => __('This address is already registered. Please sign in.')]));

        $this->post("/register/{$token}", $this->validForm())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_the_mailed_link_path_resolves_to_the_form(): void
    {
        // Guards the contract between RegistrationLinkNotification's URL and this route.
        $raw = $this->issueToken('newcomer@example.com');

        $this->get(url('/register/'.$raw))->assertOk()->assertSee('newcomer@example.com');
    }

    public function test_the_completion_endpoint_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/register/'.Str::random(40))->assertRedirect(route('register'));
        }

        $this->get('/register/'.Str::random(40))->assertStatus(429);
    }
}
