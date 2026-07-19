<?php

namespace Tests\Feature\Community\Modern;

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

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_manage_renders_roster_with_roles_viewer_role_and_pending_admin(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $member = $this->join($community, CommunityRole::Member);

        $this->actingAs($admin)->get("/community/member/manage/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/manage')
                ->where('viewerRole', 'admin')
                ->where('pendingAdminId', null)
                ->has('members.data', 2)
                ->where('members.data.0.role', 'admin') // admins first
                ->where('members.data.1.id', $member->getKey())
                ->where('members.data.1.role', 'member'));
    }

    public function test_manage_exposes_pending_admin_id_to_the_roster(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $member = $this->join($community, CommunityRole::Member);
        $community->forceFill(['pending_admin_member_id' => $member->getKey()])->save();

        $this->actingAs($admin)->get("/community/member/manage/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page->where('pendingAdminId', $member->getKey()));
    }

    public function test_manage_returns_404_for_a_non_manager(): void
    {
        $stranger = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($stranger)->get("/community/member/manage/{$community->getKey()}")->assertNotFound();
    }

    public function test_confirm_gets_redirect_to_manage_after_guards_pass(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);
        $manage = route('community.members.manage', $community);

        $this->actingAs($admin)->get($this->confirmUrl('appointSubAdmin', $community, $member))->assertRedirect($manage);
        $this->actingAs($admin)->get($this->confirmUrl('demoteSubAdmin', $community, $sub))->assertRedirect($manage);
        $this->actingAs($admin)->get($this->confirmUrl('drop', $community, $member))->assertRedirect($manage);
    }

    public function test_invalid_target_confirm_gets_still_404_under_modern(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);

        // A guard failure 404s before the Modern redirect can fire.
        $this->actingAs($admin)->get($this->confirmUrl('appointSubAdmin', $community, $sub))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('demoteSubAdmin', $community, $member))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('drop', $community, $admin))->assertNotFound();
    }

    public function test_posts_mutate_and_redirect_to_manage(): void
    {
        Event::fake([SubAdminAppointed::class]);
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $member = $this->join($community, CommunityRole::Member);
        $manage = route('community.members.manage', $community);

        $this->actingAs($admin)->post('/community/member/appointSubAdmin', ['id' => $community->getKey(), 'member_id' => $member->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $member->getKey(), 'role' => CommunityRole::SubAdmin->value]);

        $this->actingAs($admin)->post('/community/member/demoteSubAdmin', ['id' => $community->getKey(), 'member_id' => $member->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $member->getKey(), 'role' => CommunityRole::Member->value]);

        $this->actingAs($admin)->post('/community/member/drop', ['id' => $community->getKey(), 'member_id' => $member->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseMissing('community_members', ['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
    }

    public function test_show_exposes_the_manage_affordance_to_managers_only(): void
    {
        $community = Community::factory()->create();
        $admin = $this->join($community, CommunityRole::Admin);
        $sub = $this->join($community, CommunityRole::SubAdmin);
        $member = $this->join($community, CommunityRole::Member);

        // The manage link in community/show is gated on canManage; viewerRole distinguishes admin
        // (also sees Pending members) from sub-admin.
        $this->actingAs($admin)->get(route('community.show', $community))
            ->assertInertia(fn ($page) => $page->component('community/show')->where('canManage', true)->where('viewerRole', 'admin'));
        $this->actingAs($sub)->get(route('community.show', $community))
            ->assertInertia(fn ($page) => $page->where('canManage', true)->where('viewerRole', 'sub_admin'));
        $this->actingAs($member)->get(route('community.show', $community))
            ->assertInertia(fn ($page) => $page->where('canManage', false));
    }

    private function join(Community $community, CommunityRole $role): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function confirmUrl(string $path, Community $community, Member $member): string
    {
        return "/community/member/{$path}?id={$community->getKey()}&member_id={$member->getKey()}";
    }
}
