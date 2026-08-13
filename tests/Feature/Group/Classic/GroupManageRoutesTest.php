<?php

namespace Tests\Feature\Group\Classic;

use App\Features\Group\Events\AdminTransferRequested;
use App\Features\Group\Events\SubAdminAppointed;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GroupManageRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $group = Group::factory()->create();

        $this->get(route('group.members.manage', $group))->assertRedirect('/login');
        $this->post('/groups/1/members/appoint')->assertRedirect('/login');
        $this->post('/groups/1/members/drop')->assertRedirect('/login');
    }

    public function test_manage_page_is_available_to_admin_and_sub_admin_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->actingAs($admin)->get(route('group.members.manage', $group))
            ->assertOk()
            ->assertSee('id="page_community_memberManage"', false);
        $this->actingAs($sub)->get(route('group.members.manage', $group))->assertOk();
        $this->actingAs($member)->get(route('group.members.manage', $group))->assertNotFound();
        $this->actingAs($stranger)->get(route('group.members.manage', $group))->assertNotFound();
    }

    public function test_roster_rows_carry_the_openpne3_cell_classes(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $this->join($group, GroupRole::Member);

        $response = $this->actingAs($admin)->get(route('group.members.manage', $group))->assertOk();
        $content = (string) $response->getContent();

        // memberManageSuccess.php names every cell, and a row with no operation still prints one
        // (&nbsp;) so the columns stay aligned — here the admin's own row in all three operations.
        $response->assertSee('<td class="member">', false);
        foreach (['drop', 'sub_admin_request', 'admin_request'] as $cell) {
            $this->assertMatchesRegularExpression('#<td class="'.$cell.'">\s*&nbsp;\s*</td>#', $content);
        }
        // The pager brackets the roster, as memberManageSuccess.php's pager slot does.
        $this->assertSame(2, substr_count($content, 'class="pagerRelative"'));
    }

    public function test_admin_viewer_sees_appoint_demote_and_drop_links_per_row(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);

        $response = $this->actingAs($admin)->get(route('group.members.manage', $group));
        $response->assertOk();
        // Plain-member row: appoint + drop; sub-admin row: demote.
        $response->assertSee($this->href('group.members.appoint.show', $group, $member), false);
        $response->assertSee($this->href('group.members.drop.show', $group, $member), false);
        $response->assertSee($this->href('group.members.demote.show', $group, $sub), false);
        // No drop on the admin / sub-admin / self rows (only plain members are droppable).
        $response->assertDontSee($this->href('group.members.drop.show', $group, $admin), false);
        $response->assertDontSee($this->href('group.members.drop.show', $group, $sub), false);
    }

    public function test_sub_admin_viewer_sees_drop_links_but_no_appoint_or_demote_cells(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);

        $response = $this->actingAs($sub)->get(route('group.members.manage', $group));
        $response->assertOk();
        $response->assertSee($this->href('group.members.drop.show', $group, $member), false);
        // The sub-admin column is admin-only, so neither appoint nor demote links appear.
        $response->assertDontSee('community/member/appointSubAdmin', false);
        $response->assertDontSee('community/member/demoteSubAdmin', false);
        // A sub-admin cannot drop the admin or itself.
        $response->assertDontSee($this->href('group.members.drop.show', $group, $admin), false);
        $response->assertDontSee($this->href('group.members.drop.show', $group, $sub), false);
    }

    public function test_pending_nominee_row_shows_no_appoint_link_but_stays_droppable(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $member->getKey()])->save();

        $response = $this->actingAs($admin)->get(route('group.members.manage', $group));
        $response->assertOk();
        $response->assertDontSee($this->href('group.members.appoint.show', $group, $member), false);
        $response->assertSee($this->href('group.members.drop.show', $group, $member), false);
    }

    public function test_confirm_pages_render_with_their_own_body_ids_and_target_name(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member, 'Casey');

        $this->actingAs($admin)->get($this->confirmUrl('appoint', $group, $member))
            ->assertOk()
            ->assertSee('id="page_community_subAdminRequest"', false)
            ->assertSee('Casey');
        $this->actingAs($admin)->get($this->confirmUrl('demote', $group, $sub))
            ->assertOk()
            ->assertSee('id="page_community_removeSubAdmin"', false);
        $this->actingAs($admin)->get($this->confirmUrl('drop', $group, $member))
            ->assertOk()
            ->assertSee('id="page_community_dropMember"', false);
    }

    public function test_appoint_demote_drop_posts_mutate_and_redirect_to_manage(): void
    {
        Event::fake([SubAdminAppointed::class]);
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);
        $manage = route('group.members.manage', $group);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/appoint', ['member_id' => $member->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::SubAdmin->value,
        ]);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/demote', ['member_id' => $member->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::Member->value,
        ]);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/drop', ['member_id' => $member->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(),
        ]);
    }

    public function test_sub_admin_cannot_post_appoint_or_demote(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $otherSub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);

        $this->actingAs($sub)->post('/groups/'.$group->getKey().'/members/appoint', ['member_id' => $member->getKey()])->assertNotFound();
        $this->actingAs($sub)->post('/groups/'.$group->getKey().'/members/demote', ['member_id' => $otherSub->getKey()])->assertNotFound();
        // Roles unchanged.
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::Member->value]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $otherSub->getKey(), 'role' => GroupRole::SubAdmin->value]);
    }

    public function test_invalid_target_confirm_gets_return_404(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);

        // Drop confirm: admin / sub-admin / self are not droppable.
        $this->actingAs($admin)->get($this->confirmUrl('drop', $group, $admin))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('drop', $group, $sub))->assertNotFound();
        // Appoint confirm: a sub-admin or admin target.
        $this->actingAs($admin)->get($this->confirmUrl('appoint', $group, $sub))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('appoint', $group, $admin))->assertNotFound();
        // Appoint confirm: the pending transfer nominee is frozen.
        $group->forceFill(['pending_admin_member_id' => $member->getKey()])->save();
        $this->actingAs($admin)->get($this->confirmUrl('appoint', $group, $member))->assertNotFound();
        // Demote confirm: a plain member is not a sub-admin.
        $this->actingAs($admin)->get($this->confirmUrl('demote', $group, $member))->assertNotFound();
    }

    public function test_sidemenu_shows_management_member_link_for_managers_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);
        $link = e(route('group.members.manage', $group));

        $this->actingAs($admin)->get(route('group.show', $group))->assertOk()->assertSee($link, false);
        $this->actingAs($sub)->get(route('group.show', $group))->assertOk()->assertSee($link, false);
        $this->actingAs($member)->get(route('group.show', $group))->assertOk()->assertDontSee($link, false);
    }

    public function test_admin_viewer_sees_take_over_links_and_the_nominee_status(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $response = $this->actingAs($admin)->get(route('group.members.manage', $group));
        $response->assertOk();
        // Nominee row: the take-over status text, no take-over link.
        $response->assertSee('You are taking over this', false);
        $response->assertDontSee($this->href('group.members.transfer.show', $group, $nominee), false);
        // Admin row: no take-over link (cannot transfer to self).
        $response->assertDontSee($this->href('group.members.transfer.show', $group, $admin), false);
        // Other member + sub-admin rows keep their take-over link while a transfer is pending
        // (a new request replaces the nominee — replace-on-new-request, OpenPNE 3 parity).
        $response->assertSee($this->href('group.members.transfer.show', $group, $member), false);
        $response->assertSee($this->href('group.members.transfer.show', $group, $sub), false);
    }

    public function test_sub_admin_viewer_sees_no_take_over_column(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $this->join($group, GroupRole::Member);

        $this->actingAs($sub)->get(route('group.members.manage', $group))
            ->assertOk()
            ->assertDontSee('community/member/transferAdmin', false);
    }

    public function test_transfer_confirm_renders_for_member_and_sub_admin_targets(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin, 'Sammy');
        $member = $this->join($group, GroupRole::Member, 'Casey');

        $this->actingAs($admin)->get($this->confirmUrl('transfer', $group, $member))
            ->assertOk()
            ->assertSee('id="page_community_changeAdminRequest"', false)
            ->assertSee('Casey');
        // A sub-admin nominee is allowed (OpenPNE 3 parity).
        $this->actingAs($admin)->get($this->confirmUrl('transfer', $group, $sub))
            ->assertOk()
            ->assertSee('id="page_community_changeAdminRequest"', false)
            ->assertSee('Sammy');
    }

    public function test_transfer_confirm_404_for_admin_target_current_nominee_and_non_admin_viewer(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        // The admin (self) target and the current nominee are refused.
        $this->actingAs($admin)->get($this->confirmUrl('transfer', $group, $admin))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('transfer', $group, $nominee))->assertNotFound();
        // A sub-admin viewer cannot request a transfer (admin-only).
        $this->actingAs($sub)->get($this->confirmUrl('transfer', $group, $member))->assertNotFound();
    }

    public function test_transfer_post_sets_pending_then_a_new_request_replaces_it(): void
    {
        Event::fake([AdminTransferRequested::class]);
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $first = $this->join($group, GroupRole::Member);
        $second = $this->join($group, GroupRole::Member);
        $manage = route('group.members.manage', $group);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/transfer', ['member_id' => $first->getKey()])
            ->assertRedirect($manage)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => $first->getKey()]);

        // A new request to a different nominee replaces the pending one over HTTP.
        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/transfer', ['member_id' => $second->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => $second->getKey()]);
    }

    public function test_nominee_sees_the_accept_reject_banner_on_the_community_home(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $response = $this->actingAs($nominee)->get(route('group.show', $group));
        $response->assertOk();
        $response->assertSee('id="Top"', false);
        $response->assertSee('id="community_changeAdminRequest"', false);
        $response->assertSee(e(route('group.members.transfer.accept', $group)), false);
        $response->assertSee(e(route('group.members.transfer.reject', $group)), false);
    }

    public function test_non_nominee_does_not_see_the_transfer_banner(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($member)->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee('id="community_changeAdminRequest"', false);
    }

    public function test_pending_applicant_still_sees_the_approval_notice(): void
    {
        // Regression: the two Top notices share one @section, so the pending-approval branch must
        // keep rendering after the transfer banner was folded in.
        $group = Group::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $this->actingAs($applicant)->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('id="informationAboutCommunity"', false)
            ->assertDontSee('id="community_changeAdminRequest"', false);
    }

    public function test_nominee_accept_becomes_admin_and_demotes_the_old_admin(): void
    {
        $group = Group::factory()->create();
        $oldAdmin = $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($nominee)->post('/groups/'.$group->getKey().'/members/transfer/accept')
            ->assertRedirect(route('group.show', $group))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $nominee->getKey(), 'role' => GroupRole::Admin->value]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $oldAdmin->getKey(), 'role' => GroupRole::Member->value]);
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => null]);
    }

    public function test_nominee_reject_clears_pending(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($nominee)->post('/groups/'.$group->getKey().'/members/transfer/reject')
            ->assertRedirect(route('group.show', $group))
            ->assertSessionHas('status');
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => null]);
    }

    public function test_non_nominee_accept_or_reject_redirects_with_an_error_and_changes_nothing(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);
        $other = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
        $show = route('group.show', $group);

        // A non-nominee is not 404'd — the action's NoTransferPending surfaces as an error flash.
        $this->actingAs($other)->post('/groups/'.$group->getKey().'/members/transfer/accept')
            ->assertRedirect($show)->assertSessionHas('error');
        $this->actingAs($other)->post('/groups/'.$group->getKey().'/members/transfer/reject')
            ->assertRedirect($show)->assertSessionHas('error');

        // The roles and the pending seat are untouched.
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $admin->getKey(), 'role' => GroupRole::Admin->value]);
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => $nominee->getKey()]);
    }

    private function join(Group $group, GroupRole $role, ?string $name = null): Member
    {
        $member = Member::factory()->create($name !== null ? ['name' => $name] : []);
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    /** The escaped href a link cell renders, so assertSee matches the Blade-escaped `&amp;`. */
    private function href(string $route, Group $group, Member $member): string
    {
        return e(route($route, ['group' => $group->getKey(), 'member_id' => $member->getKey()]));
    }

    private function confirmUrl(string $path, Group $group, Member $member): string
    {
        return "/groups/{$group->getKey()}/members/{$path}?member_id={$member->getKey()}";
    }
}
