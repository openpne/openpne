<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberPasswordChangeTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_a_guest_cannot_post_the_password_change(): void
    {
        $this->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect('/login');
    }

    public function test_changing_the_password_with_the_correct_current_password(): void
    {
        // Factory password is 'password'.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->assertTrue(Hash::check('new-secret-pass', $member->fresh()->password));
    }

    public function test_changing_the_password_rejects_a_wrong_current_password(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'not-the-password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertSessionHasErrors('current_password');

        // Password unchanged.
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_changing_the_password_rejects_a_mismatched_confirmation(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'different-pass',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_changing_the_password_rejects_a_too_short_password(): void
    {
        // Shared passwordRules() = Password::default() (min 8).
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_the_current_session_survives_a_password_change(): void
    {
        // logoutOtherDevices re-syncs the current session's stored hash, so the acting session stays
        // authenticated.
        $member = Member::factory()->create();
        $this->actingAs($member);

        $this->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->get('/member/config')->assertOk();
    }

    public function test_a_modern_password_save_redirects_to_the_bare_config(): void
    {
        // Unlike the instant-apply preferences, the explicit password form keeps its flash on Modern.
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-secret-pass', $member->fresh()->password));
    }

    public function test_changing_the_password_rotates_the_remember_token(): void
    {
        // Rotating remember_token kills "remember me" cookies on every device.
        $member = Member::factory()->create(['remember_token' => 'old-remember-token']);

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->assertNotSame('old-remember-token', $member->fresh()->remember_token);
    }

    public function test_a_device_with_a_stale_password_hash_is_logged_out(): void
    {
        // Changing the hash out of band stands in for another device's change, so the stale session
        // must redirect to login.
        $member = Member::factory()->create();
        $this->actingAs($member)->get('/member/config')->assertOk();

        $member->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

        $this->get('/member/config')->assertRedirect('/login');
    }
}
