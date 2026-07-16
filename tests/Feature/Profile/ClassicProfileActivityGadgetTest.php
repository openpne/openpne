<?php

namespace Tests\Feature\Profile;

use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The OpenPNE 3 profile activity gadget: activityBox in the profile context (the owner's timeline). */
class ClassicProfileActivityGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string|int> $config */
    private function makeGadget(array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'profile', 'zone' => 'contents', 'name' => 'activityBox', 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => (string) $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function postFor(Member $member, Visibility $visibility, string $body): TimelinePost
    {
        return TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => $visibility, 'body' => $body]);
    }

    public function test_own_profile_shows_the_my_heading_and_links_more_to_the_owner_timeline(): void
    {
        $viewer = Member::factory()->create();
        $this->postFor($viewer, Visibility::Private, 'MyOwnActivityBody');
        $gadget = $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")
            ->assertOk()
            ->assertSee('id="activityBox_'.$gadget->id.'"', false)
            ->assertSee('class="dparts box activityBox homeRecentList"', false)
            ->assertSee('<li class="activity">', false)
            ->assertSee('My timeline')                                     // h3 (My %activity%, en term rendering)
            ->assertSee('MyOwnActivityBody')                               // own private post visible to self
            ->assertSee('/member/'.$viewer->getKey().'/timeline', false);  // More → timeline.member(owner)
    }

    public function test_other_profile_shows_the_name_heading_and_owner_posts_under_viewer_clearance(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->postFor($owner, Visibility::Members, 'OwnerMembersBody');
        $this->postFor($owner, Visibility::Private, 'OwnerPrivateBody');
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee($owner->name."'s timeline")     // h3 (:name's %activity%, en term rendering)
            ->assertDontSee('My timeline')
            ->assertSee('OwnerMembersBody')             // members-tier visible to a non-friend
            ->assertDontSee('OwnerPrivateBody')         // owner's private is clamped out for the viewer
            ->assertSee('/member/'.$owner->getKey().'/timeline', false);
    }

    public function test_profile_with_no_visible_posts_drops_the_frame(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('activityBox', false);
    }

    public function test_row_config_limits_the_owner_posts(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'body' => 'OldRow', 'created_at' => '2026-01-01 00:00:00']);
        TimelinePost::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'body' => 'NewRow', 'created_at' => '2026-03-01 00:00:00']);
        $this->makeGadget(['row' => 1]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}?page=2")
            ->assertOk()
            ->assertSee('NewRow')
            ->assertDontSee('OldRow');
    }

    public function test_guest_does_not_see_the_members_only_gadget(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->postFor($owner, Visibility::Members, 'HiddenFromGuestBody');
        $this->makeGadget();

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('activityBox', false)
            ->assertDontSee('HiddenFromGuestBody');
    }

    public function test_japanese_own_heading_matches_openpne3(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $this->postFor($viewer, Visibility::Members, 'JaOwnBody');
        $this->makeGadget();

        // OpenPNE 3 member/_activityBox (isMine): 自分の%activity%.
        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")
            ->assertOk()
            ->assertSee('自分のタイムライン');
    }
}
