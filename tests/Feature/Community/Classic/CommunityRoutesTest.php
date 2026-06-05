<?php

namespace Tests\Feature\Community\Classic;

use App\Features\Community\CommunityRole;
use App\Features\Community\Events\CommunityJoinRequested;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CommunityRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/community/search')->assertRedirect('/login');
        $this->get('/community/1')->assertRedirect('/login');
        $this->post('/community/join')->assertRedirect('/login');
    }

    public function test_show_page_renders_with_community_body_id(): void
    {
        $community = Community::factory()->create(['name' => 'Tokyo Runners']);

        $response = $this->actingAs(Member::factory()->create())->get(route('community.show', $community));

        $response->assertOk();
        $response->assertSee('id="page_community_home"', false);
        $response->assertSee('Tokyo Runners');
    }

    public function test_show_page_for_unknown_community_returns_404(): void
    {
        $this->actingAs(Member::factory()->create())->get('/community/999999')->assertNotFound();
    }

    public function test_search_route_is_not_captured_by_the_show_wildcard(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get('/community/search');

        $response->assertOk();
        $response->assertSee('id="page_community_search"', false);
    }

    public function test_search_filters_by_keyword(): void
    {
        $member = Member::factory()->create();
        Community::factory()->create(['name' => 'Tokyo Runners']);
        Community::factory()->create(['name' => 'Osaka Cooks']);

        $response = $this->actingAs($member)->get('/community/search?keyword=Runners');

        $response->assertOk();
        $response->assertSee('Tokyo Runners');
        $response->assertDontSee('Osaka Cooks');
    }

    public function test_join_list_shows_another_members_communities(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create(['name' => 'Bob']);
        $community = Community::factory()->create(['name' => 'Bobs Club']);
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $owner->getKey()]);

        $response = $this->actingAs($viewer)->get("/community/joinList?id={$owner->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_community_joinlist"', false);
        $response->assertSee('Bobs Club');
    }

    public function test_creating_a_community_makes_the_creator_admin(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/community/edit', [
            'name' => 'New Club',
            'description' => 'Hello',
            'register_policy' => 1,
            'community_category_id' => null,
        ]);

        $community = Community::where('name', 'New Club')->firstOrFail();
        $response->assertRedirect(route('community.show', $community));
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => CommunityRole::Admin->value,
        ]);
    }

    public function test_edit_page_is_available_to_admin_and_sub_admin_but_not_others(): void
    {
        $community = Community::factory()->create();
        $admin = $this->memberWithRole($community, CommunityRole::Admin);
        $sub = $this->memberWithRole($community, CommunityRole::SubAdmin);
        $stranger = Member::factory()->create();

        $this->actingAs($admin)->get(route('community.edit', ['id' => $community->getKey()]))->assertOk();
        $this->actingAs($sub)->get(route('community.edit', ['id' => $community->getKey()]))->assertOk();
        $this->actingAs($stranger)->get(route('community.edit', ['id' => $community->getKey()]))->assertNotFound();
    }

    public function test_join_confirm_page_renders_then_join_creates_a_pending_request(): void
    {
        Event::fake([CommunityJoinRequested::class]);
        $community = Community::factory()->approval()->create();
        $member = Member::factory()->create();

        $this->actingAs($member)->get(route('community.join.show', ['id' => $community->getKey()]))
            ->assertOk()
            ->assertSee('id="page_community_join"', false);

        $response = $this->actingAs($member)->post('/community/join', ['id' => $community->getKey()]);

        $response->assertRedirect(route('community.show', $community));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('community_join_requests', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
        Event::assertDispatched(CommunityJoinRequested::class);
    }

    public function test_pending_page_and_approval_are_admin_only(): void
    {
        $community = Community::factory()->approval()->create();
        $admin = $this->memberWithRole($community, CommunityRole::Admin);
        $applicant = Member::factory()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        // A non-admin cannot see the queue.
        $this->actingAs($applicant)->get(route('community.members.pending', ['id' => $community->getKey()]))->assertNotFound();

        $response = $this->actingAs($admin)->get(route('community.members.pending', ['id' => $community->getKey()]));
        $response->assertOk();
        $response->assertSee('id="page_community_memberManage"', false);

        $approve = $this->actingAs($admin)->post('/community/member/approve', [
            'id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $approve->assertRedirect(route('community.members.pending', ['id' => $community->getKey()]));
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
            'role' => CommunityRole::Member->value,
        ]);
        $this->assertDatabaseCount('community_join_requests', 0);
    }

    public function test_non_admin_cannot_approve_members(): void
    {
        $community = Community::factory()->approval()->create();
        $stranger = Member::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $this->actingAs($stranger)->post('/community/member/approve', [
            'id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ])->assertNotFound();
        $this->assertDatabaseCount('community_members', 0);
    }

    public function test_delete_confirm_and_delete_are_admin_only(): void
    {
        $community = Community::factory()->create();
        $admin = $this->memberWithRole($community, CommunityRole::Admin);
        $member = $this->memberWithRole($community, CommunityRole::Member);

        $this->actingAs($member)->get(route('community.delete.show', $community))->assertNotFound();
        $this->actingAs($member)->post(route('community.delete', $community))->assertNotFound();

        $this->actingAs($admin)->get(route('community.delete.show', $community))
            ->assertOk()
            ->assertSee('id="page_community_delete"', false);

        $this->actingAs($admin)->post(route('community.delete', $community))
            ->assertRedirect(route('community.search'));
        $this->assertDatabaseMissing('communities', ['id' => $community->getKey()]);
    }

    private function memberWithRole(Community $community, CommunityRole $role): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }
}
