<?php

namespace Tests\Feature\Community\Classic;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The OpenPNE 3 community operations: the class-less link list that closes the home page, the
 * join/quit confirmations' preview rows, and the search form's create link.
 */
class CommunityOperationsParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_administrator_may_edit_but_neither_join_nor_leave(): void
    {
        $community = Community::factory()->create();
        $admin = $this->joined($community, CommunityRole::Admin);

        $response = $this->actingAs($admin)->get(route('community.show', $community))->assertOk();

        $response->assertSee($this->link(route('community.edit', ['id' => $community->getKey()]), 'Edit this community'), false);
        $response->assertDontSee(route('community.quit.show', ['id' => $community->getKey()]), false);
        $response->assertDontSee(route('community.join.show', ['id' => $community->getKey()]), false);
    }

    public function test_the_sub_administrator_may_edit_and_leave(): void
    {
        $community = Community::factory()->create();
        $this->joined($community, CommunityRole::Admin);
        $subAdmin = $this->joined($community, CommunityRole::SubAdmin);

        $response = $this->actingAs($subAdmin)->get(route('community.show', $community))->assertOk();

        $response->assertSee($this->link(route('community.edit', ['id' => $community->getKey()]), 'Edit this community'), false);
        $response->assertSee($this->link(route('community.quit.show', ['id' => $community->getKey()]), 'Leave this community'), false);
    }

    public function test_a_plain_member_may_only_leave(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $response = $this->actingAs($member)->get(route('community.show', $community))->assertOk();

        $response->assertSee($this->link(route('community.quit.show', ['id' => $community->getKey()]), 'Leave this community'), false);
        $response->assertDontSee(route('community.edit', ['id' => $community->getKey()]), false);
        $response->assertDontSee(route('community.join.show', ['id' => $community->getKey()]), false);
    }

    public function test_an_applicant_gets_the_waiting_notice_but_no_join_link(): void
    {
        // OpenPNE 3 offered Join here too, but its join page rendered an error for an applicant;
        // OpenPNE 4's confirm redirects them straight back, so the link would be a no-op — the
        // Top notice carries the state instead.
        $community = Community::factory()->approval()->create();
        $applicant = Member::factory()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $response = $this->actingAs($applicant)->get(route('community.show', $community))->assertOk();

        $response->assertDontSee($this->link(route('community.join.show', ['id' => $community->getKey()]), 'Join this community'), false);
        $response->assertSee('waiting for the participation approval', false);
    }

    public function test_a_stranger_only_gets_the_join_link(): void
    {
        $community = Community::factory()->create();

        $response = $this->actingAs(Member::factory()->create())
            ->get(route('community.show', $community))->assertOk();

        $response->assertSee($this->link(route('community.join.show', ['id' => $community->getKey()]), 'Join this community'), false);
        $response->assertDontSee(route('community.quit.show', ['id' => $community->getKey()]), false);
        $response->assertDontSee(route('community.edit', ['id' => $community->getKey()]), false);
    }

    public function test_the_operations_are_a_class_less_list_outside_the_details_box(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $response = $this->actingAs($member)->get(route('community.show', $community))->assertOk();

        // The list closes the details box rather than sitting in its .operation footer.
        $response->assertSeeInOrder([
            'id="communityHome"',
            '</table>',
            '</div>',
            '<ul>',
            route('community.quit.show', ['id' => $community->getKey()]),
        ], false);
        // Roster and delete moved out: the roster lives in the sidemenu, delete inside the editor.
        $response->assertDontSee(route('community.delete.show', $community), false);
    }

    public function test_the_join_confirmation_previews_the_community(): void
    {
        $community = Community::factory()->create(['name' => 'Tokyo Runners']);

        $response = $this->actingAs(Member::factory()->create())
            ->get(route('community.join.show', ['id' => $community->getKey()]))->assertOk();

        $this->assertPreviewRows($response->getContent(), $community);
    }

    public function test_the_quit_confirmation_previews_the_community(): void
    {
        $community = Community::factory()->create(['name' => 'Tokyo Runners']);
        $this->joined($community, CommunityRole::Admin);
        $member = $this->joined($community);

        $response = $this->actingAs($member)
            ->get(route('community.quit.show', ['id' => $community->getKey()]))->assertOk();

        $this->assertPreviewRows($response->getContent(), $community);
    }

    public function test_the_search_form_offers_the_create_link(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get(route('community.search'))->assertOk();

        // The parts frame renders a moreInfo option after the form, inside the same box.
        $response->assertSeeInOrder([
            'id="searchCommunity"',
            '</form>',
            '<div class="moreInfo">',
            '<a href="'.route('community.edit').'">Create a new community</a>',
        ], false);
    }

    private function assertPreviewRows(string $html, Community $community): void
    {
        $home = route('community.show', $community);

        $this->assertStringContainsString('<th>Photo</th>', $html);
        // The photo cell links the 76px thumbnail to the community home.
        $this->assertMatchesRegularExpression(
            '#<td><a href="'.preg_quote($home, '#').'"><img [^>]*76[^>]*>\s*</a> </td>#',
            $html
        );
        $this->assertStringContainsString('<th>Community</th>', $html);
        $this->assertStringContainsString('<td><a href="'.$home.'">'.$community->name.'</a></td>', $html);
    }

    private function link(string $url, string $label): string
    {
        return '<li><a href="'.$url.'">'.$label.'</a></li>';
    }

    private function joined(Community $community, CommunityRole $role = CommunityRole::Member): Member
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
