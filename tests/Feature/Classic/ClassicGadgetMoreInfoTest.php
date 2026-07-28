<?php

namespace Tests\Feature\Classic;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Gadget;
use App\Models\Member;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The moreInfo footer OpenPNE 3's parts frame gave the member-image and grid gadgets: the links out
 * of a box that only ever shows a slice. Its entries turn on the *subject*, not the viewer, so
 * another member's box offers the list and not the viewer's own settings.
 */
class ClassicGadgetMoreInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_member_image_box_offers_the_photo_and_profile_links(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('home', 'memberImageBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('<ul class="moreInfo">', false)
            ->assertSee(route('member.avatar.edit'))
            ->assertSee('Edit Photo')
            ->assertSee(route('member.profile.mine_compat'))
            ->assertSee('Show Profile');
    }

    public function test_another_members_image_box_has_no_more_info(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->makeGadget('profile', 'memberImageBox');

        // OpenPNE 3's remaining entry there, "Show more Photos", has no OpenPNE 4 counterpart, so the
        // frame stays off rather than render an empty list.
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('memberImageBox', false)
            ->assertDontSee('moreInfo', false);
    }

    public function test_own_profile_image_box_keeps_the_links(): void
    {
        $owner = Member::factory()->create();
        $this->makeGadget('profile', 'memberImageBox');

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee(route('member.avatar.edit'));
    }

    public function test_friend_grid_counts_the_whole_list_not_the_visible_slice(): void
    {
        $member = $this->memberWithFriends(10); // one past the default 3 × 3 grid
        $this->makeGadget('home', 'friendListBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('Show all(10)')
            ->assertSee('/friend/list?id='.$member->getKey());
    }

    public function test_own_friend_grid_offers_the_management_link_and_another_members_does_not(): void
    {
        $owner = $this->memberWithFriends(2);
        $viewer = Member::factory()->create();
        $this->makeGadget('profile', 'friendListBox');

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee(route('friend.manage'))
            ->assertSee('Manage my friends');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('Show all(2)')                             // the subject's total, not the viewer's
            ->assertSee('/friend/list?id='.$owner->getKey())
            ->assertDontSee('Manage my friends');
    }

    public function test_community_grid_counts_the_whole_list_and_links_to_the_subjects_join_list(): void
    {
        $member = $this->memberInCommunities(10); // one past the default 3 × 3 grid
        $this->makeGadget('home', 'communityJoinListBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('Show all(10)')
            ->assertSee('/community/joinList?id='.$member->getKey());
    }

    public function test_community_grid_crowns_only_the_communities_the_subject_administers(): void
    {
        $member = Member::factory()->create();
        $administered = Community::factory()->create(['name' => 'AdministeredCommunity']);
        CommunityMember::factory()->admin()->create([
            'community_id' => $administered->getKey(),
            'member_id' => $member->getKey(),
        ]);
        CommunityMember::factory()->create([
            'community_id' => Community::factory()->create(['name' => 'JoinedCommunity'])->getKey(),
            'member_id' => $member->getKey(),
        ]);
        $this->makeGadget('home', 'communityJoinListBox');

        $response = $this->actingAs($member)->get('/')->assertOk();

        $response->assertSee('AdministeredCommunity')->assertSee('JoinedCommunity');
        $this->assertSame(1, substr_count($response->getContent(), '<p class="crown">'));
    }

    public function test_an_empty_grid_drops_the_more_info_with_the_box(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('home', 'friendListBox');
        $this->makeGadget('home', 'communityJoinListBox');

        // The footer must not survive the box it belongs to: no friends, no communities, no links.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('moreInfo', false);
    }

    public function test_japanese_more_info_labels_match_openpne3(): void
    {
        $member = Member::factory()->create(['locale' => 'ja']);
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $member->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $member->getKey()],
        ]);
        $this->makeGadget('home', 'memberImageBox');
        $this->makeGadget('home', 'friendListBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('写真を編集する')
            ->assertSee('プロフィール確認')
            ->assertSee('全てを見る(1)')
            ->assertSee('マイフレンド管理');
    }

    private function makeGadget(string $context, string $name): Gadget
    {
        $gadget = Gadget::create(['context' => $context, 'zone' => 'sideMenu', 'name' => $name, 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function memberWithFriends(int $count): Member
    {
        $member = Member::factory()->create();
        foreach (Member::factory()->count($count)->create() as $friend) {
            DB::table('friendships')->insert([
                ['member_id' => $member->getKey(), 'friend_id' => $friend->getKey()],
                ['member_id' => $friend->getKey(), 'friend_id' => $member->getKey()],
            ]);
        }

        return $member;
    }

    private function memberInCommunities(int $count): Member
    {
        $member = Member::factory()->create();
        foreach (Community::factory()->count($count)->create() as $community) {
            CommunityMember::factory()->create([
                'community_id' => $community->getKey(),
                'member_id' => $member->getKey(),
            ]);
        }

        return $member;
    }
}
