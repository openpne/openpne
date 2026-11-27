<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OpenPNE 3 keeps the admin culture in the session, apart from the member config. */
class AdminLocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_only_switch_does_not_touch_the_administrators_member_locale(): void
    {
        $admin = Member::factory()->administrator()->create(['locale' => 'ja']);

        $this->actingAsAdmin($admin)
            ->post('/admin/locale/session', ['locale' => 'en'])
            ->assertNoContent();

        $this->assertSame('ja', $admin->fresh()->locale);
        $this->get('/admin')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_the_member_toggle_leaves_the_panel_language_alone(): void
    {
        $admin = Member::factory()->administrator()->create(['locale' => 'ja']);

        $this->actingAsAdmin($admin)->post('/admin/locale/session', ['locale' => 'en']);
        $this->post('/locale', ['locale' => 'ja']);

        $this->get('/admin')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_a_member_without_the_role_cannot_switch_the_panel_language(): void
    {
        $this->actingAs(Member::factory()->create(), 'member')
            ->post('/admin/locale/session', ['locale' => 'en'])
            ->assertForbidden();
    }

    public function test_member_route_by_contrast_persists_the_members_locale(): void
    {
        $member = Member::factory()->create(['locale' => 'ja']);

        $this->actingAs($member, 'member')
            ->post('/locale', ['locale' => 'en']);

        $this->assertSame('en', $member->fresh()->locale);
    }

    public function test_panel_header_renders_the_locale_switcher(): void
    {
        $this->actingAsAdmin()
            ->get('/admin')
            ->assertOk()
            ->assertSee('locale/session');
    }
}
