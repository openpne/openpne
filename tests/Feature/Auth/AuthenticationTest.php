<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/login'));
    }

    public function test_members_can_authenticate_with_valid_credentials(): void
    {
        $member = Member::factory()->create();

        $response = $this->post('/login', [
            'email' => $member->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($member);
        $response->assertRedirect('/dashboard');
    }

    public function test_members_cannot_authenticate_with_invalid_password(): void
    {
        $member = Member::factory()->create();

        $this->post('/login', [
            'email' => $member->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_members_can_logout(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_guests_are_redirected_from_dashboard_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_members_can_access_dashboard(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('auth.user.id', $member->id)
            ->where('auth.user.name', $member->name)
            ->where('auth.user.email', $member->email)
        );
    }

    public function test_root_redirects_to_login_for_guests(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_redirects_to_dashboard_for_authenticated_members(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertRedirect('/dashboard');
    }
}
