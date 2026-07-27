<?php

namespace Tests\Feature\Community\Classic;

use App\Features\Community\CommunityRole;
use App\Features\Community\Events\AdminTransferRequested;
use App\Features\Community\Events\SubAdminAppointed;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_admin_viewer_sees_take_over_links_and_the_nominee_status(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);
        $nominee = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $response = $this->actingAs($admin)->get(route('community.members.manage', $community));
        $response->assertOk();
        // Nominee row: the take-over status text, no take-over link.
        $response->assertSee('You are taking over this', false);
        $response->assertDontSee($this->href('community.members.transfer.show', $community, $nominee), false);
        // Admin row: no take-over link (cannot transfer to self).
        $response->assertDontSee($this->href('community.members.transfer.show', $community, $admin), false);
        // Other member + sub-admin rows keep their take-over link while a transfer is pending
        // (a new request replaces the nominee — replace-on-new-request, OpenPNE 3 parity).
        $response->assertSee($this->href('community.members.transfer.show', $community, $member), false);
        $response->assertSee($this->href('community.members.transfer.show', $community, $sub), false);
    }

    public function test_sub_admin_viewer_sees_no_take_over_column(): void
    {
        $community = Community::factory()->create();
        $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $this->join($community, CommunityRole::Member);

        $this->actingAs($sub)->get(route('community.members.manage', $community))
            ->assertOk()
            ->assertDontSee('community/member/transferAdmin', false);
    }

    public function test_transfer_confirm_renders_for_member_and_sub_admin_targets(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin, 'Sammy');
        $member = $this->join($community, CommunityRole::Member, 'Casey');

        $this->actingAs($admin)->get($this->confirmUrl('transferAdmin', $community, $member))
            ->assertOk()
            ->assertSee('id="page_community_changeAdminRequest"', false)
            ->assertSee('Casey');
        // A sub-admin nominee is allowed (OpenPNE 3 parity).
        $this->actingAs($admin)->get($this->confirmUrl('transferAdmin', $community, $sub))
            ->assertOk()
            ->assertSee('id="page_community_changeAdminRequest"', false)
            ->assertSee('Sammy');
    }

    public function test_transfer_confirm_404_for_admin_target_current_nominee_and_non_admin_viewer(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);
        $nominee = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        // The admin (self) target and the current nominee are refused.
        $this->actingAs($admin)->get($this->confirmUrl('transferAdmin', $community, $admin))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('transferAdmin', $community, $nominee))->assertNotFound();
        // A sub-admin viewer cannot request a transfer (admin-only).
        $this->actingAs($sub)->get($this->confirmUrl('transferAdmin', $community, $member))->assertNotFound();
    }

    public function test_transfer_post_sets_pending_then_a_new_request_replaces_it(): void
    {
        Event::fake([AdminTransferRequested::class]);
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $first = $this->join($community, CommunityRole::Member);
        $second = $this->join($community, CommunityRole::Member);
        $manage = route('community.members.manage', $community);

        $this->actingAs($admin)->post('/community/member/transferAdmin', ['id' => $community->getKey(), 'member_id' => $first->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'pending_admin_member_id' => $first->getKey()]);

        // A new request to a different nominee replaces the pending one over HTTP.
        $this->actingAs($admin)->post('/community/member/transferAdmin', ['id' => $community->getKey(), 'member_id' => $second->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'pending_admin_member_id' => $second->getKey()]);
    }

    public function test_nominee_sees_the_accept_reject_banner_on_the_community_home(): void
    {
        $community = Community::factory()->create();
        $this->join($community, CommunityRole::Admin);
        $nominee = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $response = $this->actingAs($nominee)->get(route('community.show', $community));
        $response->assertOk();
        $response->assertSee('id="Top"', false);
        $response->assertSee('id="community_changeAdminRequest"', false);
        $response->assertSee(e(route('community.members.transfer.accept')), false);
        $response->assertSee(e(route('community.members.transfer.reject')), false);
    }

    public function test_non_nominee_does_not_see_the_transfer_banner(): void
    {
        $community = Community::factory()->create();
        $this->join($community, CommunityRole::Admin);
        $member = $this->join($community, CommunityRole::Member);
        $nominee = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($member)->get(route('community.show', $community))
            ->assertOk()
            ->assertDontSee('id="community_changeAdminRequest"', false);
    }

    public function test_pending_applicant_still_sees_the_approval_notice(): void
    {
        // Regression: the two Top notices share one @section, so the pending-approval branch must
        // keep rendering after the transfer banner was folded in.
        $community = Community::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $this->actingAs($applicant)->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('id="informationAboutCommunity"', false)
            ->assertDontSee('id="community_changeAdminRequest"', false);
    }

    public function test_nominee_accept_becomes_admin_and_demotes_the_old_admin(): void
    {
        $community = Community::factory()->create();
        $oldAdmin = $this->join($community, CommunityRole::Admin);
        $nominee = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($nominee)->post('/community/member/acceptTransfer', ['id' => $community->getKey()])
            ->assertRedirect(route('community.show', $community))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $nominee->getKey(), 'role' => CommunityRole::Admin->value]);
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $oldAdmin->getKey(), 'role' => CommunityRole::Member->value]);
        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'pending_admin_member_id' => null]);
    }

    public function test_nominee_reject_clears_pending(): void
    {
        $community = Community::factory()->create();
        $this->join($community, CommunityRole::Admin);
        $nominee = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($nominee)->post('/community/member/rejectTransfer', ['id' => $community->getKey()])
            ->assertRedirect(route('community.show', $community))
            ->assertSessionHas('status');
        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'pending_admin_member_id' => null]);
    }

    public function test_non_nominee_accept_or_reject_redirects_with_an_error_and_changes_nothing(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $nominee = $this->join($community, CommunityRole::Member);
        $other = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
        $show = route('community.show', $community);

        // A non-nominee is not 404'd — the action's NoTransferPending surfaces as an error flash.
        $this->actingAs($other)->post('/community/member/acceptTransfer', ['id' => $community->getKey()])
            ->assertRedirect($show)->assertSessionHas('error');
        $this->actingAs($other)->post('/community/member/rejectTransfer', ['id' => $community->getKey()])
            ->assertRedirect($show)->assertSessionHas('error');

        // The roles and the pending seat are untouched.
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $admin->getKey(), 'role' => CommunityRole::Admin->value]);
        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'pending_admin_member_id' => $nominee->getKey()]);
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
