<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->post('/forgot-password', ['email' => $member->email]);

        Notification::assertSentTo($member, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $member->email]);
    }

    public function test_no_reset_link_is_sent_for_an_unknown_email(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => 'stranger@example.com']);

        Notification::assertNothingSent();
        // Fortify surfaces the unknown address as a validation error (not enumeration-hardened).
        $response->assertSessionHasErrors('email');
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $member = Member::factory()->create();
        $token = Password::broker('members')->createToken($member);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $member->email,
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secret-password', $member->fresh()->password));
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
}
