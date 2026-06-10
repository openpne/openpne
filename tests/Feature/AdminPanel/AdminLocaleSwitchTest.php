<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel switches language via a session-only route. A co-logged-in member's durable
 * locale (members.locale) must never change when the operator toggles the panel language —
 * OpenPNE 3 keeps admin culture in the session, isolated from member config.
 */
class AdminLocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_only_switch_does_not_touch_a_co_logged_in_members_locale(): void
    {
        $member = Member::factory()->create(['locale' => 'ja']);
        $admin = AdminUser::factory()->create();

        $this->actingAs($member, 'member')
            ->actingAs($admin, 'admin')
            ->post('/locale/session', ['locale' => 'en'])
            ->assertNoContent();

        $this->assertSame('en', session('locale'));
        $this->assertSame('ja', $member->fresh()->locale);
    }

    public function test_member_route_by_contrast_persists_the_members_locale(): void
    {
        $member = Member::factory()->create(['locale' => 'ja']);

        $this->actingAs($member, 'member')
            ->post('/locale', ['locale' => 'en']);

        $this->assertSame('en', $member->fresh()->locale);
    }

    public function test_login_screen_renders_the_locale_switcher(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('locale/session');
    }

    public function test_panel_header_renders_the_locale_switcher(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('locale/session');
    }
}
