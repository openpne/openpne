<?php

namespace Tests\Feature\Profile;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The OpenPNE 3 profile timeline gadget: timelineProfile (the profile owner's recent timeline). */
class ClassicProfileTimelineGadgetTest extends TestCase
{
    use RefreshDatabase;

    private function makeGadget(): Gadget
    {
        $gadget = Gadget::create(['context' => 'profile', 'zone' => 'contents', 'name' => 'timelineProfile', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function postFor(Member $member, Visibility $visibility, string $body): TimelinePost
    {
        return TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => $visibility, 'body' => $body]);
    }

    public function test_own_profile_renders_the_timeline_without_a_post_link(): void
    {
        $viewer = Member::factory()->create();
        $this->postFor($viewer, Visibility::Private, 'MyOwnProfileBody');
        $gadget = $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")
            ->assertOk()
            ->assertSee('id="profileTimeline_'.$gadget->id.'"', false)
            ->assertSee('class="dparts profileTimeline"', false)
            ->assertSee("A member's timeline")           // h3 (en term rendering)
            ->assertDontSee('/timeline/new', false)      // no post link: OpenPNE 3 had none here
            ->assertSee('class="timeline-post"', false)  // _post partial reused
            ->assertSee('MyOwnProfileBody');             // own private post is visible to self
    }

    public function test_other_profile_shows_posts_without_a_post_link(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->postFor($owner, Visibility::Members, 'OwnerProfileBody');
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('class="dparts profileTimeline"', false)
            ->assertSee('OwnerProfileBody')
            ->assertDontSee('/timeline/new', false);     // no post link on someone else's profile
    }

    public function test_other_profile_with_no_visible_posts_drops_the_frame(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->makeGadget();

        // No rows and no post link would leave an empty frame, so the whole box is dropped.
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('profileTimeline', false);
    }

    public function test_own_empty_profile_keeps_the_frame_with_the_empty_line(): void
    {
        $viewer = Member::factory()->create();
        $gadget = $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")
            ->assertOk()
            ->assertSee('id="profileTimeline_'.$gadget->id.'"', false)
            ->assertSee('posts to show.')
            ->assertDontSee('/timeline/new', false)
            ->assertDontSee('class="timeline-post"', false);
    }

    public function test_guest_does_not_see_the_members_only_gadget(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->postFor($owner, Visibility::Members, 'HiddenFromGuestBody');
        $this->makeGadget();

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('profileTimeline', false)
            ->assertDontSee('HiddenFromGuestBody');
    }

    public function test_japanese_heading_matches_openpne3(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $this->postFor($owner, Visibility::Members, 'JaHeadingBody');
        $this->makeGadget();

        // OpenPNE 3 _timelineProfile template: メンバーの{activity}.
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('メンバーのタイムライン');
    }
}
