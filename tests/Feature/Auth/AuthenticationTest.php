<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders_the_classic_surface_by_default(): void
    {
        // tenant_default_surface is 'classic', so a guest gets the OpenPNE 3 Blade shell with the
        // page_member_login body id and the pre-login insecure_page class.
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('id="page_member_login"', false);
        $response->assertSee('insecure_page', false);
        $response->assertSee('name="email"', false);
    }

    public function test_login_screen_renders_the_modern_surface_when_selected(): void
    {
        config()->set('openpne.tenant_default_surface', 'modern');

        $this->get('/login')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/login'));
    }

    public function test_openpne3_login_url_redirects_to_the_canonical_login(): void
    {
        // OpenPNE 3 served login at /member/login/*; the legacy URL stays reachable.
        $this->get('/member/login')->assertRedirect('/login');
        $this->get('/member/login/foo/bar')->assertRedirect('/login');
    }

    public function test_members_can_authenticate_with_valid_credentials(): void
    {
        $member = Member::factory()->create();

        $response = $this->post('/login', [
            'email' => $member->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($member);
        $response->assertRedirect('/'); // surface-aware root landing
    }

    public function test_authenticated_members_visiting_login_are_redirected_through_the_root(): void
    {
        // Not straight to /dashboard (the framework default), so the landing stays surface-aware.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/login')->assertRedirect('/');
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

    public function test_root_renders_the_classic_home_for_authenticated_members_by_default(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="page_member_home"', false);
    }
}
