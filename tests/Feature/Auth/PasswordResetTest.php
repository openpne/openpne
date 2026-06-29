<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetMemberPassword;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_renders_the_classic_surface_by_default(): void
    {
        $this->get('/forgot-password')
            ->assertStatus(200)
            ->assertSee('id="page_opAuthMailAddress_passwordRecovery"', false)
            ->assertSee('insecure_page', false)
            ->assertSee('name="email"', false);
    }

    public function test_forgot_password_screen_renders_the_modern_surface_when_selected(): void
    {
        config()->set('openpne.tenant_default_surface', 'modern');

        $this->get('/forgot-password')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/forgot-password'));
    }

    public function test_reset_password_screen_renders_the_classic_surface_with_token_and_email(): void
    {
        $this->get('/reset-password/the-token?email=member@example.com')
            ->assertStatus(200)
            ->assertSee('id="page_opAuthMailAddress_passwordRecoveryComplete"', false)
            ->assertSee('value="the-token"', false)
            ->assertSee('value="member@example.com"', false);
    }

    public function test_reset_password_screen_renders_the_modern_surface_when_selected(): void
    {
        config()->set('openpne.tenant_default_surface', 'modern');

        $this->get('/reset-password/the-token?email=member@example.com')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/reset-password')
                ->where('token', 'the-token')
                ->where('email', 'member@example.com')
            );
    }

    public function test_openpne3_password_recovery_urls_redirect_to_the_request_form(): void
    {
        $this->get('/opAuthMailAddress/passwordRecovery')->assertRedirect('/forgot-password');
        $this->get('/opAuthMailAddress/passwordRecoveryComplete')->assertRedirect('/forgot-password');
    }

    public function test_reset_link_is_sent_to_a_known_member(): void
    {
        Notification::fake();
        $member = Member::factory()->create();

        $this->post('/forgot-password', ['email' => $member->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.neutral'));

        Notification::assertSentTo($member, ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $member->email]);
    }

    public function test_an_unknown_email_gets_the_same_neutral_reply_with_no_mail(): void
    {
        // Enumeration-safety: identical neutral status and no validation error, so the response is
        // indistinguishable from the known-member case above — only the mail (none) differs.
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'stranger@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.neutral'));

        Notification::assertNothingSent();
    }

    public function test_the_reset_notification_is_queued(): void
    {
        // Queued so a known address does not pay a synchronous SMTP send (a timing oracle).
        $this->assertInstanceOf(ShouldQueue::class, new ResetPasswordNotification('token', 'en'));
    }

    public function test_forgot_password_is_rate_limited_per_ip(): void
    {
        Notification::fake();

        // Distinct addresses, so the broker's per-email throttle never trips — only the per-IP limit.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => "user{$i}@example.com"])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => 'user5@example.com'])->assertStatus(429);
    }

    public function test_reset_password_post_is_rate_limited_per_ip(): void
    {
        $payload = ['token' => 'x', 'email' => 'a@example.com', 'password' => 'x', 'password_confirmation' => 'x'];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/reset-password', $payload)->assertStatus(302);
        }

        $this->post('/reset-password', $payload)->assertStatus(429);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $member = Member::factory()->create(['remember_token' => 'old-remember-token']);
        $token = Password::broker('members')->createToken($member);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $member->email,
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $this->assertTrue(Hash::check('new-secret-password', $fresh->password));
        // The remember-me token is rotated, so a "remember me" cookie on another device stops working.
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
    }

    public function test_reset_purges_the_members_database_sessions(): void
    {
        // On the database session driver the reset deletes the member's server-side sessions outright,
        // so another device's logged-in session cannot survive (auth.session is only the fallback).
        config()->set('session.driver', 'database');
        $member = Member::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $member->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'agent',
            'payload' => 'x',
            'last_activity' => 1700000000,
        ]);

        app(ResetMemberPassword::class)->reset($member, [
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_reset_is_rejected_with_an_invalid_token(): void
    {
        $member = Member::factory()->create();
        $original = $member->password;

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $member->email,
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame($original, $member->fresh()->password);
    }

    public function test_reset_voids_a_pending_email_change(): void
    {
        // A reset answers a possible compromise, so it must also drop any pending email change (an
        // attacker who requested one before the reset would otherwise still hold a live token).
        $member = Member::factory()->create();
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'pending@example.com',
            'token' => hash('sha256', str_repeat('e', 40)), 'created_at' => now(),
        ]);

        app(ResetMemberPassword::class)->reset($member, [
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }
}
