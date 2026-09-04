<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Members and administrators use separate guards with independent session
 * state, so authentication on one must never satisfy the other. Guarded in
 * both directions.
 */
class AdminGuardSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_session_cannot_reach_the_admin_panel(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member, 'member')
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_session_can_reach_the_admin_panel(): void
    {
        $admin = AdminUser::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk();
    }

    public function test_admin_session_cannot_reach_member_routes(): void
    {
        $admin = AdminUser::factory()->create();

        // setUser on the admin guard only: actingAs() would also switch the default guard, masking
        // the separation this asserts.
        $this->app['auth']->guard('admin')->setUser($admin);

        $this->get('/dashboard')->assertRedirect('/login');
    }
}
