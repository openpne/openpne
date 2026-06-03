<?php

namespace Tests\Feature\Home;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassicHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_sees_the_classic_home(): void
    {
        $member = Member::factory()->create(['name' => 'Hanako']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="page_member_home"', false) // OpenPNE 3 body-id hook
            ->assertSee('id="home_index"', false)
            ->assertSee('Hanako');
    }

    public function test_guest_at_root_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_redirects_to_the_dashboard_when_the_default_surface_is_modern(): void
    {
        config(['openpne.tenant_default_surface' => 'modern']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertRedirect('/dashboard');
    }

    public function test_member_index_alias_redirects_to_root(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member')->assertRedirect('/');
    }
}
