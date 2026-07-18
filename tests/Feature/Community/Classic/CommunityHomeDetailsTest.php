<?php

namespace Tests\Feature\Community\Classic;

use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3 community home fidelity: the sidemenu member grid crowns the admin, and the center
 * column renders the full details listBox. Labels resolve in the request locale (en here), so the
 * assertions are the rendered English output (%community% term substituted to "community").
 */
class CommunityHomeDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_crown_icon_is_vendored(): void
    {
        $this->assertFileExists(public_path('images/icon_crown.gif'));
    }

    public function test_the_admin_gets_a_crown_but_the_sub_admin_does_not(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $sub = Member::factory()->create(['name' => 'SubBob']);
        $plain = Member::factory()->create(['name' => 'MemberCarol']);
        CommunityMember::factory()->admin()->create(['community_id' => $community->id, 'member_id' => $admin->id]);
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->id, 'member_id' => $sub->id]);
        CommunityMember::factory()->member()->create(['community_id' => $community->id, 'member_id' => $plain->id]);

        $response = $this->actingAs($plain)->get(route('community.show', $community))->assertOk();

        $crown = '<p class="crown"><img src="'.asset('images/icon_crown.gif').'" alt="admin"></p>';
        // The crown sits in the admin's photo cell, immediately before the admin's grid link
        // (adjacency, so a crown on some other cell cannot satisfy this via a later admin link).
        $this->assertMatchesRegularExpression(
            '#'.preg_quote($crown, '#').'\s*<a href="'.preg_quote(route('member.profile.show', $admin), '#').'"#',
            $response->getContent()
        );
        // Exactly one crown across the page — the sub-admin and plain member cells carry none.
        $this->assertSame(1, substr_count($response->getContent(), '<p class="crown">'));
    }

    public function test_the_details_list_box_renders_the_op3_rows_in_order(): void
    {
        $category = CommunityCategory::factory()->create(['name' => 'Sports']);
        $community = Community::factory()->approval()->create([
            'name' => 'Tokyo Runners',
            'description' => 'Hello world',
            'community_category_id' => $category->getKey(),
        ]);
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $sub = Member::factory()->create(['name' => 'SubBob']);
        CommunityMember::factory()->admin()->create(['community_id' => $community->id, 'member_id' => $admin->id]);
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->id, 'member_id' => $sub->id]);

        $response = $this->actingAs($admin)->get(route('community.show', $community))->assertOk();

        $response->assertSee('id="communityHome"', false);
        $response->assertSeeInOrder([
            '<th>community Name</th>',
            '<th>community Category</th>',
            '<th>Date Created</th>',
            '<th>Administrator</th>',
            '<th>Sub Administrator</th>',
            '<th>Count of Members</th>',
            '<th>Register policy</th>',
            '<th>community Description</th>',
            '<th>Authority to Read topic</th>',
            '<th>Authority to Create topic</th>',
        ], false);

        // The Administrator row links to the admin's profile.
        $response->assertSee('<td><a href="'.route('member.profile.show', $admin).'">AdminAlice</a></td>', false);
        // The category name and the enum value labels render.
        $response->assertSee('Sports');
        $response->assertSee('Approval required'); // register_policy = Approval
        $response->assertSee('Anyone can read');   // topic_read_access default = Everyone
        $response->assertSee('Members can post');  // topic_post_authority default = Members
    }

    public function test_the_sub_administrator_row_is_absent_without_a_sub_admin(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $plain = Member::factory()->create(['name' => 'MemberCarol']);
        CommunityMember::factory()->admin()->create(['community_id' => $community->id, 'member_id' => $admin->id]);
        CommunityMember::factory()->member()->create(['community_id' => $community->id, 'member_id' => $plain->id]);

        $this->actingAs($plain)->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('<th>Administrator</th>', false)
            ->assertDontSee('<th>Sub Administrator</th>', false);
    }

    public function test_a_community_without_memberships_renders_without_an_administrator_row(): void
    {
        // Community::factory() creates no admin membership, so adminMember is null.
        $community = Community::factory()->create();

        $this->actingAs(Member::factory()->create())->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('id="communityHome"', false)
            ->assertDontSee('<th>Administrator</th>', false);
    }
}
