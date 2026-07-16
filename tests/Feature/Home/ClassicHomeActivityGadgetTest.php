<?php

namespace Tests\Feature\Home;

use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The OpenPNE 3 home activity gadgets: activityBox (viewer + friends, server-rendered rows) and
 * allMemberActivityBox (whole SNS, optional post form).
 */
class ClassicHomeActivityGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string|int> $config */
    private function makeGadget(string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => (string) $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function postFor(Member $member, Visibility $visibility, string $body, ?string $createdAt = null): TimelinePost
    {
        $attrs = ['member_id' => $member->getKey(), 'visibility' => $visibility, 'body' => $body];
        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
        }

        return TimelinePost::factory()->create($attrs);
    }

    // activityBox (home) --------------------------------------------------------

    public function test_activity_box_renders_the_openpne3_dom_for_self_and_friends(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $this->postFor($friend, Visibility::Friends, 'FriendActivityBody');
        $gadget = $this->makeGadget('activityBox');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="activityBox_'.$gadget->id.'"', false)
            ->assertSee('class="dparts box activityBox homeRecentList"', false)
            ->assertSee('id="activityBox_'.$gadget->id.'_timeline" class="activities"', false)
            ->assertSee('<li class="activity">', false)
            ->assertSee('class="box_memberImage"', false)
            ->assertSee('class="box_body"', false)
            ->assertSee('<strong class="name">', false)
            ->assertSee('timeline of my friend')      // h3 (%activity% of %my_friend%, en term rendering)
            ->assertSee('FriendActivityBody')
            ->assertSee('/timeline"', false)           // More → timeline.index (absolute url ends here)
            ->assertDontSee('/timeline/new', false);   // no inline post link (unlike allMemberActivityBox)
    }

    public function test_activity_box_excludes_a_strangers_members_post(): void
    {
        $viewer = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->postFor($stranger, Visibility::Members, 'StrangerMembersBody');
        $this->postFor($viewer, Visibility::Members, 'OwnBody');
        $this->makeGadget('activityBox');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('OwnBody')
            ->assertDontSee('StrangerMembersBody'); // FriendFeed drops a non-friend's all-members post
    }

    public function test_activity_box_is_dropped_when_empty(): void
    {
        $viewer = Member::factory()->create();
        $this->makeGadget('activityBox');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('activityBox', false);
    }

    public function test_activity_box_honors_the_row_config_and_ignores_the_page_query(): void
    {
        $viewer = Member::factory()->create();
        $this->postFor($viewer, Visibility::Members, 'OldOwnBody', createdAt: '2026-01-01 00:00:00');
        $this->postFor($viewer, Visibility::Members, 'NewOwnBody', createdAt: '2026-03-01 00:00:00');
        $this->makeGadget('activityBox', ['row' => 1]);

        $this->actingAs($viewer)->get('/?page=2')
            ->assertOk()
            ->assertSee('NewOwnBody')
            ->assertDontSee('OldOwnBody');
    }

    public function test_activity_box_delete_link_only_on_own_posts_and_public_flag_only_below_members(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $own = $this->postFor($viewer, Visibility::Friends, 'OwnFriendsBody');   // below Members → public_flag shown
        $ownMembers = $this->postFor($viewer, Visibility::Members, 'OwnMembersBody'); // Members → no public_flag
        $friendPost = $this->postFor($friend, Visibility::Friends, 'FriendsBody');
        $this->makeGadget('activityBox');

        $response = $this->actingAs($viewer)->get('/')->assertOk();

        // Delete link only on the viewer's own posts.
        $response->assertSee('/timeline/deleteConfirm/'.$own->getKey(), false);
        $response->assertSee('/timeline/deleteConfirm/'.$ownMembers->getKey(), false);
        $response->assertDontSee('/timeline/deleteConfirm/'.$friendPost->getKey(), false);
        // public_flag appears (a below-Members post exists) but not for the Members-tier one.
        $response->assertSee('class="public_flag"', false);
    }

    public function test_activity_box_guest_does_not_see_the_members_only_gadget(): void
    {
        $this->postFor(Member::factory()->create(), Visibility::Members, 'GuestHiddenBody');
        $this->makeGadget('activityBox');

        $this->get('/')
            ->assertRedirect('/login'); // the Classic home requires a member
    }

    public function test_activity_box_japanese_heading_matches_openpne3(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $this->postFor($viewer, Visibility::Members, 'JaBody');
        $this->makeGadget('activityBox');

        // OpenPNE 3 friend/_activityBox: %my_friend%の%activity%.
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('マイフレンドのタイムライン');
    }

    // allMemberActivityBox ------------------------------------------------------

    public function test_all_member_activity_box_includes_a_strangers_members_post(): void
    {
        $viewer = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->postFor($stranger, Visibility::Members, 'StrangerAllMemberBody');
        $gadget = $this->makeGadget('allMemberActivityBox');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="activityBox_'.$gadget->id.'"', false)
            ->assertSee('class="dparts box activityBox homeRecentList"', false)
            ->assertSee("SNS Member's timeline")        // h3 (en term rendering)
            ->assertSee('StrangerAllMemberBody')         // a stranger's all-members post is in
            ->assertDontSee('class="public_flag"', false); // Members-tier posts carry no public_flag
    }

    public function test_all_member_activity_box_shows_the_post_link_only_when_the_form_is_enabled(): void
    {
        $viewer = Member::factory()->create();
        $this->postFor(Member::factory()->create(), Visibility::Members, 'AllMemberBody');

        $this->makeGadget('allMemberActivityBox', ['is_viewable_activity_form' => 1]);
        $this->actingAs($viewer)->get('/')->assertOk()->assertSee('/timeline/new', false);

        Gadget::query()->delete();
        $this->makeGadget('allMemberActivityBox', ['is_viewable_activity_form' => 0]);
        $this->actingAs($viewer)->get('/')->assertOk()->assertDontSee('/timeline/new', false);
    }

    public function test_all_member_activity_box_empty_drops_without_form_but_keeps_frame_with_form(): void
    {
        $viewer = Member::factory()->create();

        // is_viewable_activity_form=0 and no posts → dropped entirely.
        $this->makeGadget('allMemberActivityBox', ['is_viewable_activity_form' => 0]);
        $this->actingAs($viewer)->get('/')->assertOk()->assertDontSee('activityBox', false);

        // is_viewable_activity_form=1 and no posts → frame + post link stay.
        Gadget::query()->delete();
        $gadget = $this->makeGadget('allMemberActivityBox', ['is_viewable_activity_form' => 1]);
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="activityBox_'.$gadget->id.'"', false)
            ->assertSee('/timeline/new', false)
            ->assertDontSee('<li class="activity">', false);
    }
}
