<?php

namespace Tests\Feature\Timeline;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Timeline\CommunityTimelineAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The row-level gate for a community post. TimelineAccess is shared by the permalink and by
 * FilePolicy (image bytes), so each case here covers both reads at once.
 */
class CommunityTimelineAccessTest extends TestCase
{
    use RefreshDatabase;

    // The community's read gate ------------------------------------------------

    public function test_everyone_community_admits_any_signed_in_member(): void
    {
        $community = $this->community(TopicReadAccess::Everyone);
        $post = $this->postIn($community);

        $this->assertTrue(TimelineAccess::canView(Member::factory()->create(), $post));
    }

    public function test_members_only_community_excludes_non_members(): void
    {
        $community = $this->community(TopicReadAccess::MembersOnly);
        $post = $this->postIn($community);

        $this->assertFalse(TimelineAccess::canView(Member::factory()->create(), $post));
        $this->assertTrue(TimelineAccess::canView($this->joined($community), $post));
    }

    // Guests and the feature unit ----------------------------------------------

    public function test_a_guest_never_reads_a_community_post(): void
    {
        $community = $this->community(TopicReadAccess::Everyone);

        $this->assertFalse(TimelineAccess::canView(null, $this->postIn($community)));
    }

    public function test_a_guest_is_refused_even_when_the_row_stores_a_web_public_visibility(): void
    {
        // Members is the write-side invariant; a legacy or corrupt Open value must not become a
        // read grant, which it would if the gate fell through to the visibility ladder.
        $community = $this->community(TopicReadAccess::Everyone);
        $post = $this->postIn($community);
        DB::table('timeline_posts')->where('id', $post->getKey())->update(['visibility' => Visibility::Open->value]);

        $this->assertFalse(TimelineAccess::canView(null, $post->fresh()));
    }

    public function test_the_community_unit_closes_the_post(): void
    {
        $community = $this->community(TopicReadAccess::Everyone);
        $post = $this->postIn($community);
        $member = $this->joined($community);

        $this->switchOff(Feature::Community);

        $this->assertFalse(TimelineAccess::canView($member, $post));
    }

    // Blocks and the author ----------------------------------------------------

    public function test_an_author_blocking_the_viewer_hides_their_community_post(): void
    {
        $community = $this->community(TopicReadAccess::Everyone);
        $author = $this->joined($community);
        $viewer = $this->joined($community);
        $post = $this->postIn($community, $author);

        DB::table('member_blocks')->insert([
            'blocker_id' => $author->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertFalse(TimelineAccess::canView($viewer, $post));
        $this->assertTrue(TimelineAccess::canView($author, $post));
    }

    public function test_an_author_who_left_still_has_their_permalink_served(): void
    {
        // OpenPNE 3 dropped an ex-member's posts from the community feed but kept serving the
        // permalink (timeline/show carries no membership check). The split is deliberate.
        $community = $this->community(TopicReadAccess::Everyone);
        $author = $this->joined($community);
        $post = $this->postIn($community, $author);

        CommunityMember::where('community_id', $community->getKey())
            ->where('member_id', $author->getKey())->delete();

        $this->assertTrue(TimelineAccess::canView($this->joined($community), $post->fresh()));
    }

    // Posting ------------------------------------------------------------------

    public function test_only_members_may_post_even_when_everyone_may_read(): void
    {
        $community = $this->community(TopicReadAccess::Everyone);
        $member = $this->joined($community);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTimelineAccess::canViewTimeline($community, $stranger));
        $this->assertFalse(CommunityTimelineAccess::canPost($community, $stranger));
        $this->assertTrue(CommunityTimelineAccess::canPost($community, $member));
    }

    public function test_an_admins_only_board_does_not_silence_the_timeline(): void
    {
        // topic_post_authority gates the board, not this: a plain member still posts here.
        $community = $this->community(TopicReadAccess::Everyone, TopicPostAuthority::AdminsOnly);

        $this->assertTrue(CommunityTimelineAccess::canPost($community, $this->joined($community)));
    }

    private function switchOff(Feature $feature): void
    {
        $this->setSnsSetting($feature->settingKey(), false);
        $this->freshRequestState();
    }

    private function community(TopicReadAccess $read, ?TopicPostAuthority $post = null): Community
    {
        return Community::factory()->create(array_filter([
            'topic_read_access' => $read,
            'topic_post_authority' => $post,
        ]));
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

    private function postIn(Community $community, ?Member $author = null): TimelinePost
    {
        return TimelinePost::factory()->inCommunity($community)->create([
            'member_id' => ($author ?? $this->joined($community))->getKey(),
        ]);
    }
}
