<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The registration email-entry half (OpenPNE 3 opAuthMailAddress/requestRegisterURL): enter an
 * address → a token is mailed → a neutral confirmation. The token-gated form + completion are
 * covered separately once they land.
 */
class RegistrationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_entry_screen_renders_the_classic_surface_by_default(): void
    {
        $this->get('/register')
            ->assertStatus(200)
            ->assertSee('id="page_opAuthMailAddress_requestRegisterURL"', false)
            ->assertSee('insecure_page', false)
            ->assertSee('name="email"', false);
    }

    public function test_email_entry_screen_renders_the_modern_surface_when_selected(): void
    {
        config()->set('openpne.tenant_default_surface', 'modern');

        $this->get('/register')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/register-email'));
    }

    public function test_a_new_email_is_issued_a_token_and_mailed_a_link(): void
    {
        Notification::fake();

        $this->post('/register', ['email' => 'newcomer@example.com'])
            ->assertRedirect(route('register.sent'));

        $this->assertDatabaseHas('registration_tokens', ['email' => 'newcomer@example.com']);
        Notification::assertSentOnDemand(RegistrationLinkNotification::class);
    }

    public function test_an_already_registered_email_is_silently_ignored(): void
    {
        // Enumeration-safety: identical neutral outcome, no token, no mail — stricter than the
        // password-reset flow, which surfaces an unknown address as a validation error.
        Notification::fake();
        $member = Member::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', ['email' => $member->email])
            ->assertRedirect(route('register.sent'));

        $this->assertDatabaseMissing('registration_tokens', ['email' => $member->email]);
        Notification::assertNothingSent();
    }

    public function test_the_email_is_normalized_before_issuing_and_matching(): void
    {
        Notification::fake();
        Member::factory()->create(['email' => 'taken@example.com']);

        // A mixed-case new address is stored lowercased.
        $this->post('/register', ['email' => 'Newcomer@Example.com'])->assertRedirect(route('register.sent'));
        $this->assertDatabaseHas('registration_tokens', ['email' => 'newcomer@example.com']);

        // A mixed-case existing address still matches the member and is ignored.
        $this->post('/register', ['email' => 'TAKEN@example.com'])->assertRedirect(route('register.sent'));
        $this->assertDatabaseMissing('registration_tokens', ['email' => 'taken@example.com']);
    }

    public function test_only_one_live_token_is_kept_per_email(): void
    {
        Notification::fake();

        $this->post('/register', ['email' => 'newcomer@example.com']);
        $this->post('/register', ['email' => 'newcomer@example.com']);

        $this->assertSame(1, RegistrationToken::where('email', 'newcomer@example.com')->count());
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        Notification::fake();

        $this->from('/register')->post('/register', ['email' => 'not-an-email'])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('registration_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_the_entry_endpoint_is_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', ['email' => 'flood@example.com'])->assertRedirect(route('register.sent'));
        }

        $this->post('/register', ['email' => 'flood@example.com'])->assertStatus(429);
    }
}
