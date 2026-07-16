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

/** The OpenPNE 3 home timeline gadgets: timelineAll (whole SNS) and timelineFriend (self + friends). */
class ClassicHomeTimelineGadgetTest extends TestCase
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

    public function test_timeline_all_renders_the_openpne3_dom_and_includes_a_strangers_members_post(): void
    {
        $viewer = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->postFor($stranger, Visibility::Members, 'StrangerAllTierBody');
        $gadget = $this->makeGadget('timelineAll');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeAllTimeline_'.$gadget->id.'"', false)
            ->assertSee('class="dparts homeAllTimeline"', false)
            ->assertSee("All members' timeline")           // h3 (en term rendering)
            ->assertSee('/timeline/new', false)            // post link
            ->assertSee('class="timeline-post"', false)    // _post partial reused
            ->assertSee('StrangerAllTierBody');            // stranger's all-members post is in the all tier
    }

    public function test_timeline_all_frame_and_post_link_render_when_empty(): void
    {
        $viewer = Member::factory()->create();
        $gadget = $this->makeGadget('timelineAll');

        // Like diaryMyList, the frame and post link stay even with no posts; no list is rendered.
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeAllTimeline_'.$gadget->id.'"', false)
            ->assertSee('/timeline/new', false)
            ->assertDontSee('class="timeline-post"', false)
            ->assertDontSee('timeline-list', false);
    }

    public function test_timeline_friend_excludes_a_strangers_members_post(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $this->postFor($friend, Visibility::Friends, 'FriendTierBody');
        $this->postFor($stranger, Visibility::Members, 'StrangerMembersBody');
        $gadget = $this->makeGadget('timelineFriend');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeFriendTimeline_'.$gadget->id.'"', false)
            ->assertSee('class="dparts homeFriendTimeline"', false)
            ->assertSee('Timeline of Friend')      // h3 (%Activity% of %Friend%, en term rendering)
            ->assertSee('FriendTierBody')          // a friend's friends-only post is in
            ->assertDontSee('StrangerMembersBody'); // a stranger's all-members post is out (HomeFeed difference)
    }

    public function test_timeline_all_honors_the_limit_config_and_ignores_the_page_query(): void
    {
        $viewer = Member::factory()->create();
        $this->postFor($viewer, Visibility::Members, 'OldOwnBody', createdAt: '2026-01-01 00:00:00');
        $this->postFor($viewer, Visibility::Members, 'NewOwnBody', createdAt: '2026-03-01 00:00:00');
        $this->makeGadget('timelineAll', ['limit' => 1]);

        // limit=1 keeps only the newest; take()->get() ignores the host page's ?page=.
        $this->actingAs($viewer)->get('/?page=2')
            ->assertOk()
            ->assertSee('NewOwnBody')
            ->assertDontSee('OldOwnBody');
    }

    public function test_japanese_headings_match_openpne3(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $this->makeGadget('timelineAll');
        $this->makeGadget('timelineFriend');

        // OpenPNE 3 templates: SNSメンバー全員の{activity} and {friend}の{activity}.
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('SNSメンバー全員のタイムライン')
            ->assertSee('フレンドのタイムライン');
    }
}
