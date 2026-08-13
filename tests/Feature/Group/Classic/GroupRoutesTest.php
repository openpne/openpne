<?php

namespace Tests\Feature\Group\Classic;

use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\Events\GroupJoinRequested;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GroupRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/groups')->assertRedirect('/login');
        $this->get('/groups/1')->assertRedirect('/login');
        $this->post('/groups/1/join')->assertRedirect('/login');
    }

    public function test_show_page_renders_with_community_body_id(): void
    {
        $group = Group::factory()->create(['name' => 'Tokyo Runners']);

        $response = $this->actingAs(Member::factory()->create())->get(route('group.show', $group));

        $response->assertOk();
        $response->assertSee('id="page_community_home"', false);
        $response->assertSee('Tokyo Runners');
    }

    public function test_show_page_for_unknown_community_returns_404(): void
    {
        $this->actingAs(Member::factory()->create())->get('/groups/999999')->assertNotFound();
    }

    public function test_home_renders_layout_a_with_the_member_sidemenu(): void
    {
        $group = Group::factory()->create(['name' => 'Tokyo Runners']);
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $member = Member::factory()->create(['name' => 'MemberBob']);
        GroupMember::create(['group_id' => $group->id, 'member_id' => $admin->id, 'role' => GroupRole::Admin]);
        GroupMember::create(['group_id' => $group->id, 'member_id' => $member->id, 'role' => GroupRole::Member]);

        $response = $this->actingAs($member)->get(route('group.show', $group));

        $response->assertOk();
        $response->assertSee('id="LayoutA"', false);  // the community home layout
        $response->assertSee('id="Left"', false);      // the sidemenu column
        // OpenPNE 3 named the community member grid friendList (a copy-paste it never fixed).
        $response->assertSee('id="friendList"', false);
        $response->assertSee('AdminAlice');
        $response->assertSee('MemberBob');
    }

    public function test_pending_applicant_sees_the_approval_notice_in_the_top_row(): void
    {
        $group = Group::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $response = $this->actingAs($applicant)->get(route('group.show', $group));

        $response->assertOk();
        $response->assertSee('id="Top"', false); // the Top slot, present only while pending
        $response->assertSee('waiting for the participation approval', false);
    }

    public function test_search_route_is_not_captured_by_the_show_wildcard(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get('/groups');

        $response->assertOk();
        $response->assertSee('id="page_community_search"', false);
    }

    public function test_search_filters_by_keyword(): void
    {
        $member = Member::factory()->create();
        Group::factory()->create(['name' => 'Tokyo Runners']);
        Group::factory()->create(['name' => 'Osaka Cooks']);

        // OpenPNE 3 query shape: community[name]=...
        $response = $this->actingAs($member)->get('/groups?'.http_build_query(['community' => ['name' => 'Runners']]));

        $response->assertOk();
        $response->assertSee('Tokyo Runners');
        $response->assertDontSee('Osaka Cooks');
    }

    public function test_search_results_draw_the_openpne3_result_band(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create([
            'name' => 'Tokyo Runners',
            'description' => "Morning runs.\nEveryone welcome.",
        ]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get('/groups')->assertOk();

        // _partsSearchResultList.php: the photo cell spans the name / member count / description rows.
        $response->assertSee('<td rowspan="3" class="photo">', false);
        $response->assertSee('<a href="'.route('group.show', $group).'">Details</a>', false);
        $response->assertSee('<th>Count of Members</th><td>1</td>', false);
        // A later row's newlines collapse into the OpenPNE 3 three-row cell.
        $response->assertSee('<td>Morning runs. Everyone welcome.</td>', false);
    }

    public function test_search_accepts_the_openpne3_search_query_alias(): void
    {
        $member = Member::factory()->create();
        Group::factory()->create(['name' => 'Tokyo Runners']);
        Group::factory()->create(['name' => 'Osaka Cooks']);

        $response = $this->actingAs($member)->get('/groups?search_query=Runners');

        $response->assertOk();
        $response->assertSee('Tokyo Runners');
        $response->assertDontSee('Osaka Cooks');
    }

    public function test_search_spans_admin_only_categories(): void
    {
        $member = Member::factory()->create();
        $adminOnly = GroupCategory::factory()->adminOnly()->create(['name' => 'Staff']);
        $group = Group::factory()->create(['name' => 'Staff Club', 'group_category_id' => $adminOnly->getKey()]);

        // The filter lists every category (not just member-creatable) and finds groups in it.
        $response = $this->actingAs($member)->get('/groups?'.http_build_query([
            'community' => ['community_category_id' => $adminOnly->getKey()],
        ]));

        $response->assertOk();
        $response->assertSee('Staff'); // category present in the filter select
        $response->assertSee('Staff Club');
    }

    public function test_editing_keeps_an_admin_only_category(): void
    {
        $adminOnly = GroupCategory::factory()->adminOnly()->create(['name' => 'Staff']);
        $group = Group::factory()->create(['group_category_id' => $adminOnly->getKey()]);
        $admin = $this->memberWithRole($group, GroupRole::Admin);

        // The current admin-only category is offered in the edit form.
        $this->actingAs($admin)->get(route('group.edit', ['id' => $group->getKey()]))
            ->assertOk()
            ->assertSee('Staff');

        // Saving with the same category keeps it (not nulled, not rejected).
        $response = $this->actingAs($admin)->post('/groups/edit?'.http_build_query(['id' => $group->getKey()]), [
            'name' => $group->name,
            'description' => 'updated',
            'register_policy' => $group->register_policy->slug(),
            'topic_read_access' => $group->topic_read_access->slug(),
            'topic_post_authority' => $group->topic_post_authority->slug(),
            'group_category_id' => $adminOnly->getKey(),
        ]);

        $response->assertRedirect(route('group.show', $group));
        $this->assertDatabaseHas('groups', [
            'id' => $group->getKey(),
            'group_category_id' => $adminOnly->getKey(),
            'description' => 'updated',
        ]);
    }

    /**
     * OpenPNE 3's two opCommunityTopicPlugin settings, radios with its own choice captions. A
     * members-only community must render with its stored choice checked — not the default.
     */
    public function test_editing_renders_the_topic_settings_with_the_stored_choice_checked(): void
    {
        $group = Group::factory()->create([
            'topic_read_access' => TopicReadAccess::MembersOnly,
            'topic_post_authority' => TopicPostAuthority::AdminsOnly,
        ]);
        $admin = $this->memberWithRole($group, GroupRole::Admin);

        $content = (string) $this->actingAs($admin)
            ->get(route('group.edit', ['id' => $group->getKey()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('Authority to Read %Topic%')), $content);
        $this->assertStringContainsString(
            '<input type="radio" name="topic_read_access" value="members_only" class="input_radio" checked>', $content);
        $this->assertStringContainsString(
            '<input type="radio" name="topic_post_authority" value="admins_only" class="input_radio" checked>', $content);
        // The other option renders unchecked, with OpenPNE 3's caption.
        $this->assertStringNotContainsString(
            '<input type="radio" name="topic_read_access" value="everyone" class="input_radio" checked>', $content);
        $this->assertStringContainsString('value="everyone" class="input_radio"', $content);
        $this->assertStringContainsString(e(__('Everyone can read')), $content);
    }

    public function test_the_create_form_preselects_the_openpne3_defaults(): void
    {
        $member = Member::factory()->create();

        $content = (string) $this->actingAs($member)->get('/groups/edit')->assertOk()->getContent();

        // Both fields are required on the wire, so the create form itself supplies the defaults.
        $this->assertStringContainsString(
            '<input type="radio" name="topic_read_access" value="everyone" class="input_radio" checked>', $content);
        $this->assertStringContainsString(
            '<input type="radio" name="topic_post_authority" value="members" class="input_radio" checked>', $content);
    }

    public function test_saving_persists_the_topic_settings(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, GroupRole::Admin);

        $this->actingAs($admin)->post('/groups/edit?'.http_build_query(['id' => $group->getKey()]), [
            'name' => $group->name,
            'register_policy' => 'open',
            'topic_read_access' => 'members_only',
            'topic_post_authority' => 'admins_only',
            'is_join_notification_enabled' => '1',
        ])->assertRedirect(route('group.show', $group));

        $this->assertDatabaseHas('groups', [
            'id' => $group->getKey(),
            'topic_read_access' => TopicReadAccess::MembersOnly->value,
            'topic_post_authority' => TopicPostAuthority::AdminsOnly->value,
        ]);
    }

    /**
     * The enum fields are REQUIRED slugs: an absent field must never silently reset a members-only
     * community open, and the old int protocol must not creep back in.
     */
    public function test_saving_rejects_missing_unknown_or_integer_enum_values(): void
    {
        $group = Group::factory()->create([
            'topic_read_access' => TopicReadAccess::MembersOnly,
        ]);
        $admin = $this->memberWithRole($group, GroupRole::Admin);
        $url = '/groups/edit?'.http_build_query(['id' => $group->getKey()]);
        $base = ['name' => $group->name, 'register_policy' => 'open', 'topic_post_authority' => 'members'];

        // Absent
        $this->actingAs($admin)->post($url, $base)->assertSessionHasErrors('topic_read_access');
        // Unknown slug
        $this->actingAs($admin)->post($url, $base + ['topic_read_access' => 'sometimes'])
            ->assertSessionHasErrors('topic_read_access');
        // Raw int (the DB value) is not the wire contract
        $this->actingAs($admin)->post($url, $base + ['topic_read_access' => '2'])
            ->assertSessionHasErrors('topic_read_access');
        $this->actingAs($admin)->post($url, ['name' => $group->name, 'register_policy' => '1',
            'topic_read_access' => 'everyone', 'topic_post_authority' => 'members'])
            ->assertSessionHasErrors('register_policy');

        // And nothing moved.
        $this->assertDatabaseHas('groups', [
            'id' => $group->getKey(),
            'topic_read_access' => TopicReadAccess::MembersOnly->value,
        ]);
    }

    public function test_a_validation_error_round_trips_the_chosen_topic_settings(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, GroupRole::Admin);

        // A failing name keeps the just-chosen radios checked via old(), not the stored state.
        $editUrl = route('group.edit', ['id' => $group->getKey()]);
        $this->actingAs($admin)->from($editUrl)
            ->post('/groups/edit?'.http_build_query(['id' => $group->getKey()]), [
                'name' => '',
                'register_policy' => 'open',
                'topic_read_access' => 'members_only',
                'topic_post_authority' => 'admins_only',
            ])->assertRedirect($editUrl)->assertSessionHasErrors('name');

        // The flashed old input drives the re-render, not the stored Everyone/Members.
        $content = (string) $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();
        $this->assertStringContainsString(
            '<input type="radio" name="topic_read_access" value="members_only" class="input_radio" checked>', $content);
    }

    public function test_editing_renders_and_persists_the_join_notification_toggle(): void
    {
        $group = Group::factory()->create(['is_join_notification_enabled' => true]);
        $admin = $this->memberWithRole($group, GroupRole::Admin);

        // The hidden 0 + checkbox 1 pair renders, so the value is always submitted.
        $this->actingAs($admin)->get(route('group.edit', ['id' => $group->getKey()]))
            ->assertOk()
            ->assertSee('type="hidden" name="is_join_notification_enabled" value="0"', false);

        // Unchecking submits the hidden 0, turning it off.
        $this->actingAs($admin)->post('/groups/edit?'.http_build_query(['id' => $group->getKey()]), [
            'name' => $group->name,
            'register_policy' => $group->register_policy->slug(),
            'topic_read_access' => $group->topic_read_access->slug(),
            'topic_post_authority' => $group->topic_post_authority->slug(),
            'is_join_notification_enabled' => '0',
        ])->assertRedirect(route('group.show', $group));

        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'is_join_notification_enabled' => false]);
    }

    public function test_a_validation_error_preserves_the_unchecked_join_notification(): void
    {
        $group = Group::factory()->create(['is_join_notification_enabled' => true]);
        $admin = $this->memberWithRole($group, GroupRole::Admin);
        $editUrl = route('group.edit', ['id' => $group->getKey()]);

        // Uncheck (hidden 0) but trip validation with a blank name.
        $this->actingAs($admin)->from($editUrl)->post('/groups/edit?'.http_build_query(['id' => $group->getKey()]), [
            'name' => '',
            'register_policy' => $group->register_policy->slug(),
            'topic_read_access' => $group->topic_read_access->slug(),
            'topic_post_authority' => $group->topic_post_authority->slug(),
            'is_join_notification_enabled' => '0',
        ])->assertRedirect($editUrl)->assertSessionHasErrors('name');

        // Re-rendering reflects the unchecked choice (old '0'), not the still-true stored value.
        $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertSee('name="is_join_notification_enabled" value="1"', false)
            ->assertDontSee('value="1" checked', false);
    }

    public function test_join_list_shows_another_members_communities(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create(['name' => 'Bob']);
        $group = Group::factory()->create(['name' => 'Bobs Club']);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $owner->getKey()]);

        $response = $this->actingAs($viewer)->get("/groups/mine?id={$owner->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_community_joinlist"', false);
        $response->assertSee('Bobs Club');
    }

    public function test_join_list_photo_table_crowns_the_owners_communities_and_keeps_the_id_in_the_pager(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        foreach (Group::factory()->count(20)->create() as $group) {
            GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $owner->getKey()]);
        }
        // Highest id sorts first, so the crowned community lands on page 1.
        $led = Group::factory()->create(['name' => 'Led Club']);
        GroupMember::factory()->admin()->create(['group_id' => $led->getKey(), 'member_id' => $owner->getKey()]);

        $response = $this->actingAs($viewer)->get("/groups/mine?id={$owner->getKey()}");

        $response->assertOk();
        $response->assertSee('<tr class="photo">', false);
        $response->assertSee('>Led Club (1)</a>', false);
        // OpenPNE 3 crowns by the listed member's role, not the viewer's, so a visitor sees it too.
        $this->assertSame(1, substr_count((string) $response->getContent(), 'class="crown"'));
        $response->assertSee('mine?id='.$owner->getKey().'&amp;page=2', false);
    }

    public function test_member_list_photo_table_crowns_the_admin_instead_of_labelling_roles(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $sub = Member::factory()->create(['name' => 'SubBob']);
        $plain = Member::factory()->create(['name' => 'PlainCarol']);
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $sub->getKey()]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $plain->getKey()]);

        $response = $this->actingAs($plain)->get("/groups/{$group->getKey()}/members");

        $response->assertOk();
        $response->assertSee('id="communityMembersList"', false);
        $response->assertSee('<tr class="photo">', false);
        $response->assertSee('>AdminAlice (0)</a>', false);
        // Only the admin is crowned; the sub-admin's role no longer prints as a label.
        $this->assertSame(1, substr_count((string) $response->getContent(), 'class="crown"'));
        $response->assertDontSee('class="role"', false);
    }

    public function test_member_list_pager_keeps_the_group_across_pages(): void
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        foreach (Member::factory()->count(20)->create() as $member) {
            GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        }

        $first = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/members");
        $first->assertOk();
        // The group is in the path now, so the pager only has to keep the page number.
        $first->assertSee('/groups/'.$group->getKey().'/members?page=2', false);

        $second = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/members?page=2");
        $second->assertOk();
        $second->assertSee('21 - 21 of 21');
    }

    public function test_creating_a_community_makes_the_creator_admin(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/groups/edit', [
            'name' => 'New Club',
            'description' => 'Hello',
            'register_policy' => 'open',
            'topic_read_access' => 'everyone',
            'topic_post_authority' => 'members',
            'group_category_id' => null,
        ]);

        $group = Group::where('name', 'New Club')->firstOrFail();
        $response->assertRedirect(route('group.show', $group));
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Admin->value,
        ]);
    }

    public function test_edit_page_is_available_to_admin_and_sub_admin_but_not_others(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, GroupRole::Admin);
        $sub = $this->memberWithRole($group, GroupRole::SubAdmin);
        $stranger = Member::factory()->create();

        $this->actingAs($admin)->get(route('group.edit', ['id' => $group->getKey()]))->assertOk();
        $this->actingAs($sub)->get(route('group.edit', ['id' => $group->getKey()]))->assertOk();
        $this->actingAs($stranger)->get(route('group.edit', ['id' => $group->getKey()]))->assertNotFound();
    }

    public function test_join_confirm_page_renders_then_join_creates_a_pending_request(): void
    {
        Event::fake([GroupJoinRequested::class]);
        $group = Group::factory()->approval()->create();
        $member = Member::factory()->create();

        $this->actingAs($member)->get(route('group.join.show', ['group' => $group->getKey()]))
            ->assertOk()
            ->assertSee('id="page_community_join"', false);

        $response = $this->actingAs($member)->post('/groups/'.$group->getKey().'/join');

        $response->assertRedirect(route('group.show', $group));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('group_join_requests', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
        Event::assertDispatched(GroupJoinRequested::class);
    }

    public function test_pending_page_and_approval_are_admin_only(): void
    {
        $group = Group::factory()->approval()->create();
        $admin = $this->memberWithRole($group, GroupRole::Admin);
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        // A non-admin cannot see the queue.
        $this->actingAs($applicant)->get(route('group.members.pending', ['group' => $group->getKey()]))->assertNotFound();

        $response = $this->actingAs($admin)->get(route('group.members.pending', ['group' => $group->getKey()]));
        $response->assertOk();
        $response->assertSee('id="page_community_memberManage"', false);

        $approve = $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/approve', [
            'member_id' => $applicant->getKey(),
        ]);
        $approve->assertRedirect(route('group.members.pending', ['group' => $group->getKey()]));
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
            'role' => GroupRole::Member->value,
        ]);
        $this->assertDatabaseCount('group_join_requests', 0);
    }

    public function test_pending_page_draws_the_openpne3_manage_list(): void
    {
        $group = Group::factory()->approval()->create();
        $admin = $this->memberWithRole($group, GroupRole::Admin);
        $applicant = Member::factory()->create(['name' => 'Pat']);
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $response = $this->actingAs($admin)->get(route('group.members.pending', ['group' => $group->getKey()]))->assertOk();

        // _partsManageList.php: a 76×76 photo over the member link, then one operation per cell.
        $response->assertSee('<div class="dparts manageList" id="community_memberManage">', false);
        $response->assertSee('<div class="item"><table><tbody>', false);
        $response->assertSee('<td class="photo"><a href="'.route('member.profile.show', $applicant).'">', false);
        // The pager brackets the queue.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'class="pagerRelative"'));
    }

    public function test_non_admin_cannot_approve_members(): void
    {
        $group = Group::factory()->approval()->create();
        $stranger = Member::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $this->actingAs($stranger)->post('/groups/'.$group->getKey().'/members/approve', [
            'member_id' => $applicant->getKey(),
        ])->assertNotFound();
        $this->assertDatabaseCount('group_members', 0);
    }

    public function test_delete_confirm_and_delete_are_admin_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, GroupRole::Admin);
        $member = $this->memberWithRole($group, GroupRole::Member);

        $this->actingAs($member)->get(route('group.delete.show', $group))->assertNotFound();
        $this->actingAs($member)->post(route('group.delete', $group))->assertNotFound();

        $this->actingAs($admin)->get(route('group.delete.show', $group))
            ->assertOk()
            ->assertSee('id="page_community_delete"', false);

        $this->actingAs($admin)->post(route('group.delete', $group))
            ->assertRedirect(route('group.search'));
        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
    }

    private function memberWithRole(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }
}
