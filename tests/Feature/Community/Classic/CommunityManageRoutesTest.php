<?php

namespace Tests\Feature\Community\Classic;

use App\Features\Community\CommunityRole;
use App\Features\Community\Events\SubAdminAppointed;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CommunityManageRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();

        $this->get(route('community.members.manage', $community))->assertRedirect('/login');
        $this->post('/community/member/appointSubAdmin')->assertRedirect('/login');
        $this->post('/community/member/drop')->assertRedirect('/login');
    }

    public function test_manage_page_is_available_to_admin_and_sub_admin_only(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);
        $stranger = Member::factory()->create();

        $this->actingAs($admin)->get(route('community.members.manage', $community))
            ->assertOk()
            ->assertSee('id="page_community_memberManage"', false);
        $this->actingAs($sub)->get(route('community.members.manage', $community))->assertOk();
        $this->actingAs($member)->get(route('community.members.manage', $community))->assertNotFound();
        $this->actingAs($stranger)->get(route('community.members.manage', $community))->assertNotFound();
    }

    public function test_admin_viewer_sees_appoint_demote_and_drop_links_per_row(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);

        $response = $this->actingAs($admin)->get(route('community.members.manage', $community));
        $response->assertOk();
        // Plain-member row: appoint + drop; sub-admin row: demote.
        $response->assertSee($this->href('community.members.appoint.show', $community, $member), false);
        $response->assertSee($this->href('community.members.drop.show', $community, $member), false);
        $response->assertSee($this->href('community.members.demote.show', $community, $sub), false);
        // No drop on the admin / sub-admin / self rows (only plain members are droppable).
        $response->assertDontSee($this->href('community.members.drop.show', $community, $admin), false);
        $response->assertDontSee($this->href('community.members.drop.show', $community, $sub), false);
    }

    public function test_sub_admin_viewer_sees_drop_links_but_no_appoint_or_demote_cells(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);

        $response = $this->actingAs($sub)->get(route('community.members.manage', $community));
        $response->assertOk();
        $response->assertSee($this->href('community.members.drop.show', $community, $member), false);
        // The sub-admin column is admin-only, so neither appoint nor demote links appear.
        $response->assertDontSee('community/member/appointSubAdmin', false);
        $response->assertDontSee('community/member/demoteSubAdmin', false);
        // A sub-admin cannot drop the admin or itself.
        $response->assertDontSee($this->href('community.members.drop.show', $community, $admin), false);
        $response->assertDontSee($this->href('community.members.drop.show', $community, $sub), false);
    }

    public function test_pending_nominee_row_shows_no_appoint_link_but_stays_droppable(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $member = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $member->getKey()])->save();

        $response = $this->actingAs($admin)->get(route('community.members.manage', $community));
        $response->assertOk();
        $response->assertDontSee($this->href('community.members.appoint.show', $community, $member), false);
        $response->assertSee($this->href('community.members.drop.show', $community, $member), false);
    }

    public function test_confirm_pages_render_with_their_own_body_ids_and_target_name(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member, 'Casey');

        $this->actingAs($admin)->get($this->confirmUrl('appointSubAdmin', $community, $member))
            ->assertOk()
            ->assertSee('id="page_community_subAdminRequest"', false)
            ->assertSee('Casey');
        $this->actingAs($admin)->get($this->confirmUrl('demoteSubAdmin', $community, $sub))
            ->assertOk()
            ->assertSee('id="page_community_removeSubAdmin"', false);
        $this->actingAs($admin)->get($this->confirmUrl('drop', $community, $member))
            ->assertOk()
            ->assertSee('id="page_community_dropMember"', false);
    }

    public function test_appoint_demote_drop_posts_mutate_and_redirect_to_manage(): void
    {
        Event::fake([SubAdminAppointed::class]);
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $member = $this->join($community, CommunityRole::Member);
        $manage = route('community.members.manage', $community);

        $this->actingAs($admin)->post('/community/member/appointSubAdmin', ['id' => $community->getKey(), 'member_id' => $member->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(), 'member_id' => $member->getKey(), 'role' => CommunityRole::SubAdmin->value,
        ]);

        $this->actingAs($admin)->post('/community/member/demoteSubAdmin', ['id' => $community->getKey(), 'member_id' => $member->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(), 'member_id' => $member->getKey(), 'role' => CommunityRole::Member->value,
        ]);

        $this->actingAs($admin)->post('/community/member/drop', ['id' => $community->getKey(), 'member_id' => $member->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('community_members', [
            'community_id' => $community->getKey(), 'member_id' => $member->getKey(),
        ]);
    }

    public function test_sub_admin_cannot_post_appoint_or_demote(): void
    {
        $community = Community::factory()->create();
        $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $otherSub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);

        $this->actingAs($sub)->post('/community/member/appointSubAdmin', ['id' => $community->getKey(), 'member_id' => $member->getKey()])->assertNotFound();
        $this->actingAs($sub)->post('/community/member/demoteSubAdmin', ['id' => $community->getKey(), 'member_id' => $otherSub->getKey()])->assertNotFound();
        // Roles unchanged.
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $member->getKey(), 'role' => CommunityRole::Member->value]);
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $otherSub->getKey(), 'role' => CommunityRole::SubAdmin->value]);
    }

    public function test_invalid_target_confirm_gets_return_404(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);

        // Drop confirm: admin / sub-admin / self are not droppable.
        $this->actingAs($admin)->get($this->confirmUrl('drop', $community, $admin))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('drop', $community, $sub))->assertNotFound();
        // Appoint confirm: a sub-admin or admin target.
        $this->actingAs($admin)->get($this->confirmUrl('appointSubAdmin', $community, $sub))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('appointSubAdmin', $community, $admin))->assertNotFound();
        // Appoint confirm: the pending transfer nominee is frozen.
        $community->forceFill(['pending_admin_member_id' => $member->getKey()])->save();
        $this->actingAs($admin)->get($this->confirmUrl('appointSubAdmin', $community, $member))->assertNotFound();
        // Demote confirm: a plain member is not a sub-admin.
        $this->actingAs($admin)->get($this->confirmUrl('demoteSubAdmin', $community, $member))->assertNotFound();
    }

    public function test_sidemenu_shows_management_member_link_for_managers_only(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);
        $link = e(route('community.members.manage', $community));

        $this->actingAs($admin)->get(route('community.show', $community))->assertOk()->assertSee($link, false);
        $this->actingAs($sub)->get(route('community.show', $community))->assertOk()->assertSee($link, false);
        $this->actingAs($member)->get(route('community.show', $community))->assertOk()->assertDontSee($link, false);
    }

    private function join(Community $community, CommunityRole $role, ?string $name = null): Member
    {
        $member = Member::factory()->create($name !== null ? ['name' => $name] : []);
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    /** The escaped href a link cell renders, so assertSee matches the Blade-escaped `&amp;`. */
    private function href(string $route, Community $community, Member $member): string
    {
        return e(route($route, ['id' => $community->getKey(), 'member_id' => $member->getKey()]));
    }

    private function confirmUrl(string $path, Community $community, Member $member): string
    {
        return "/community/member/{$path}?id={$community->getKey()}&member_id={$member->getKey()}";
    }
}
