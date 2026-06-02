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

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/forgot-password'));
    }

    public function test_reset_password_screen_receives_the_token_and_email(): void
    {
        $this->get('/reset-password/the-token?email=member@example.com')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/reset-password')
                ->where('token', 'the-token')
                ->where('email', 'member@example.com')
            );
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
