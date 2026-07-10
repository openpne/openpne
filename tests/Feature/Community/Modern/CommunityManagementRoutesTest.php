<?php

namespace Tests\Feature\Community\Modern;

use App\Features\Community\Actions\JoinCommunity;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityManagementRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();

        $this->get('/community/edit')->assertRedirect('/login');
        $this->post('/community/edit')->assertRedirect('/login');
        $this->post("/community/delete/{$community->getKey()}")->assertRedirect('/login');
        $this->get("/community/member/pending?id={$community->getKey()}")->assertRedirect('/login');
    }

    public function test_modern_new_renders_create_form_with_null_community(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/community/edit')
            ->assertInertia(fn ($page) => $page
                ->component('community/edit')
                ->where('community', null)
                ->has('policies.0.value')
                ->where('canDelete', false)
            );
    }

    public function test_modern_edit_renders_form_for_a_manager(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->get("/community/edit?id={$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/edit')
                ->where('community.id', $community->getKey())
                ->where('canDelete', true)
            );
    }

    public function test_modern_edit_returns_404_for_a_non_manager(): void
    {
        $stranger = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($stranger)->get("/community/edit?id={$community->getKey()}")->assertNotFound();
    }

    public function test_modern_create_stores_community_and_redirects_to_show(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/community/edit', [
            'name' => 'Modern Community',
            'register_policy' => 1,
        ]);

        $community = Community::where('name', 'Modern Community')->firstOrFail();
        $response->assertRedirect(route('community.show', $community));
        // The creator is seeded as admin.
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_create_defaults_join_notifications_on(): void
    {
        $member = Member::factory()->create();

        // No is_join_notification_enabled in the payload → the default-on contract (not a silent off).
        $this->actingAs($member)->post('/community/edit', [
            'name' => 'Defaulted Community',
            'register_policy' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('communities', ['name' => 'Defaulted Community', 'is_join_notification_enabled' => true]);
    }

    public function test_modern_edit_exposes_the_join_notification_toggle(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create(['is_join_notification_enabled' => false]);
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->get("/community/edit?id={$community->getKey()}")
            ->assertInertia(fn ($page) => $page->where('community.isJoinNotificationEnabled', false));
    }

    public function test_modern_save_persists_the_join_notification_toggle(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create(['is_join_notification_enabled' => true]);
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)->post("/community/edit?id={$community->getKey()}", [
            'name' => $community->name,
            'register_policy' => $community->register_policy->value,
            'is_join_notification_enabled' => false,
        ])->assertRedirect(route('community.show', $community));

        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'is_join_notification_enabled' => false]);
    }

    public function test_modern_update_keeps_the_same_name_without_a_unique_error(): void
    {
        // Regression guard: CommunityRequest must resolve the community from ?id=,
        // so re-saving without changing the name does not trip the unique-name rule.
        $admin = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'Steady Name']);
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->post("/community/edit?id={$community->getKey()}", ['name' => 'Steady Name', 'register_policy' => 2])
            ->assertRedirect(route('community.show', $community));

        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'register_policy' => 2]);
    }

    public function test_modern_delete_removes_community_and_redirects_to_search(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->post("/community/delete/{$community->getKey()}")
            ->assertRedirect(route('community.search'));

        $this->assertDatabaseMissing('communities', ['id' => $community->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_sub_admin(): void
    {
        // Delete is admin-only; a sub-admin (who may edit) cannot delete.
        $subAdmin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey(), 'member_id' => $subAdmin->getKey()]);

        $this->actingAs($subAdmin)->post("/community/delete/{$community->getKey()}")->assertNotFound();
    }

    public function test_modern_pending_and_approve(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->approval()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);
        $applicant = Member::factory()->create();
        app(JoinCommunity::class)($applicant, $community);

        $this->actingAs($admin)
            ->get("/community/member/pending?id={$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/pending')
                ->where('applicants.data.0.id', $applicant->getKey())
            );

        $this->actingAs($admin)
            ->post('/community/member/approve', ['id' => $community->getKey(), 'member_id' => $applicant->getKey()])
            ->assertRedirect(route('community.members.pending', ['id' => $community->getKey()]));

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $this->assertDatabaseMissing('community_join_requests', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
    }

    public function test_modern_pending_returns_404_for_a_non_admin(): void
    {
        $stranger = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($stranger)->get("/community/member/pending?id={$community->getKey()}")->assertNotFound();
    }
}
