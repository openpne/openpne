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

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();

        $this->get('/m/community/new')->assertRedirect('/login');
        $this->post('/m/community')->assertRedirect('/login');
        $this->get("/m/community/{$community->getKey()}/edit")->assertRedirect('/login');
        $this->post("/m/community/{$community->getKey()}/delete")->assertRedirect('/login');
        $this->get("/m/community/{$community->getKey()}/pending")->assertRedirect('/login');
    }

    public function test_modern_new_renders_create_form_with_null_community(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/m/community/new')
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
            ->get("/m/community/{$community->getKey()}/edit")
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

        $this->actingAs($stranger)->get("/m/community/{$community->getKey()}/edit")->assertNotFound();
    }

    public function test_modern_create_stores_community_and_redirects_to_modern_show(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/m/community', [
            'name' => 'Modern Community',
            'register_policy' => 1,
        ]);

        $community = Community::where('name', 'Modern Community')->firstOrFail();
        $response->assertRedirect(route('community.modern.show', $community));
        // The creator is seeded as admin.
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_update_keeps_the_same_name_without_a_unique_error(): void
    {
        // Regression guard: CommunityRequest must read the id from the /m path, not only ?id=,
        // so re-saving without changing the name does not trip the unique-name rule.
        $admin = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'Steady Name']);
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->post("/m/community/{$community->getKey()}/edit", ['name' => 'Steady Name', 'register_policy' => 2])
            ->assertRedirect(route('community.modern.show', $community));

        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'register_policy' => 2]);
    }

    public function test_modern_delete_removes_community_and_redirects_to_modern_search(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->actingAs($admin)
            ->post("/m/community/{$community->getKey()}/delete")
            ->assertRedirect(route('community.modern.search'));

        $this->assertDatabaseMissing('communities', ['id' => $community->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_sub_admin(): void
    {
        // Delete is admin-only; a sub-admin (who may edit) cannot delete.
        $subAdmin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey(), 'member_id' => $subAdmin->getKey()]);

        $this->actingAs($subAdmin)->post("/m/community/{$community->getKey()}/delete")->assertNotFound();
    }

    public function test_modern_pending_and_approve(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->approval()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);
        $applicant = Member::factory()->create();
        app(JoinCommunity::class)($applicant, $community);

        $this->actingAs($admin)
            ->get("/m/community/{$community->getKey()}/pending")
            ->assertInertia(fn ($page) => $page
                ->component('community/pending')
                ->where('applicants.data.0.id', $applicant->getKey())
            );

        $this->actingAs($admin)
            ->post("/m/community/{$community->getKey()}/approve", ['member_id' => $applicant->getKey()])
            ->assertRedirect(route('community.modern.members.pending', $community));

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

        $this->actingAs($stranger)->get("/m/community/{$community->getKey()}/pending")->assertNotFound();
    }
}
