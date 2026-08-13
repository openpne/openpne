<?php

namespace Tests\Feature\Group\Modern;

use App\Features\Group\Actions\JoinGroup;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupManagementRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $group = Group::factory()->create();

        $this->get('/groups/edit')->assertRedirect('/login');
        $this->post('/groups/edit')->assertRedirect('/login');
        $this->post("/groups/{$group->getKey()}/delete")->assertRedirect('/login');
        $this->get("/groups/{$group->getKey()}/members/pending")->assertRedirect('/login');
    }

    public function test_modern_new_renders_create_form_with_null_community(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/groups/edit')
            ->assertInertia(fn ($page) => $page
                ->component('community/edit')
                ->where('group', null)
                ->has('policies.0.slug')
                ->has('topicReadChoices.0.slug')
                ->has('topicPostChoices.0.slug')
                ->where('canDelete', false)
            );
    }

    public function test_modern_edit_renders_form_for_a_manager(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->get("/groups/edit?id={$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/edit')
                ->where('group.id', $group->getKey())
                ->where('canDelete', true)
            );
    }

    public function test_modern_edit_returns_404_for_a_non_manager(): void
    {
        $stranger = Member::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($stranger)->get("/groups/edit?id={$group->getKey()}")->assertNotFound();
    }

    public function test_modern_create_stores_community_and_redirects_to_show(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/groups/edit', [
            'name' => 'Modern Group',
            'register_policy' => 'open',
            'topic_read_access' => 'everyone',
            'topic_post_authority' => 'members',
        ]);

        $group = Group::where('name', 'Modern Group')->firstOrFail();
        $response->assertRedirect(route('group.show', $group));
        // The creator is seeded as admin.
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_create_defaults_join_notifications_on(): void
    {
        $member = Member::factory()->create();

        // No is_join_notification_enabled in the payload → the default-on contract (not a silent off).
        $this->actingAs($member)->post('/groups/edit', [
            'name' => 'Defaulted Group',
            'register_policy' => 'open',
            'topic_read_access' => 'everyone',
            'topic_post_authority' => 'members',
        ])->assertRedirect();

        $this->assertDatabaseHas('groups', ['name' => 'Defaulted Group', 'is_join_notification_enabled' => true]);
    }

    public function test_modern_edit_exposes_the_join_notification_toggle(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create(['is_join_notification_enabled' => false]);
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->get("/groups/edit?id={$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('group.isJoinNotificationEnabled', false));
    }

    public function test_modern_save_persists_the_join_notification_toggle(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create(['is_join_notification_enabled' => true]);
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)->post("/groups/edit?id={$group->getKey()}", [
            'name' => $group->name,
            'register_policy' => $group->register_policy->slug(),
            'topic_read_access' => $group->topic_read_access->slug(),
            'topic_post_authority' => $group->topic_post_authority->slug(),
            'is_join_notification_enabled' => false,
        ])->assertRedirect(route('group.show', $group));

        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'is_join_notification_enabled' => false]);
    }

    public function test_modern_update_keeps_the_same_name_without_a_unique_error(): void
    {
        // Regression guard: GroupRequest must resolve the community from ?id=,
        // so re-saving without changing the name does not trip the unique-name rule.
        $admin = Member::factory()->create();
        $group = Group::factory()->create(['name' => 'Steady Name']);
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->post("/groups/edit?id={$group->getKey()}", ['name' => 'Steady Name', 'register_policy' => 'approval', 'topic_read_access' => 'everyone', 'topic_post_authority' => 'members'])
            ->assertRedirect(route('group.show', $group));

        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'register_policy' => 2]);
    }

    public function test_modern_delete_removes_community_and_redirects_to_search(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->post("/groups/{$group->getKey()}/delete")
            ->assertRedirect(route('group.search'));

        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_sub_admin(): void
    {
        // Delete is admin-only; a sub-admin (who may edit) cannot delete.
        $subAdmin = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $subAdmin->getKey()]);

        $this->actingAs($subAdmin)->post("/groups/{$group->getKey()}/delete")->assertNotFound();
    }

    public function test_modern_pending_and_approve(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->approval()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $applicant = Member::factory()->create();
        app(JoinGroup::class)($applicant, $group);

        $this->actingAs($admin)
            ->get("/groups/{$group->getKey()}/members/pending")
            ->assertInertia(fn ($page) => $page
                ->component('community/pending')
                ->where('applicants.data.0.id', $applicant->getKey())
            );

        $this->actingAs($admin)
            ->post('/groups/'.$group->getKey().'/members/approve', ['member_id' => $applicant->getKey()])
            ->assertRedirect(route('group.members.pending', ['group' => $group->getKey()]));

        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $this->assertDatabaseMissing('group_join_requests', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
    }

    public function test_modern_pending_returns_404_for_a_non_admin(): void
    {
        $stranger = Member::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($stranger)->get("/groups/{$group->getKey()}/members/pending")->assertNotFound();
    }
}
