<?php

namespace Tests\Feature\Timeline;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Timeline\CommunityTimelineAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\Group;
use App\Models\GroupMember;
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
        $group = $this->community(TopicReadAccess::Everyone);
        $post = $this->postIn($group);

        $this->assertTrue(TimelineAccess::canView(Member::factory()->create(), $post));
    }

    public function test_members_only_community_excludes_non_members(): void
    {
        $group = $this->community(TopicReadAccess::MembersOnly);
        $post = $this->postIn($group);

        $this->assertFalse(TimelineAccess::canView(Member::factory()->create(), $post));
        $this->assertTrue(TimelineAccess::canView($this->joined($group), $post));
    }

    // Guests and the feature unit ----------------------------------------------

    public function test_a_guest_never_reads_a_community_post(): void
    {
        $group = $this->community(TopicReadAccess::Everyone);

        $this->assertFalse(TimelineAccess::canView(null, $this->postIn($group)));
    }

    public function test_a_guest_is_refused_even_when_the_row_stores_a_web_public_visibility(): void
    {
        // Members is the write-side invariant; a legacy or corrupt Open value must not become a
        // read grant, which it would if the gate fell through to the visibility ladder.
        $group = $this->community(TopicReadAccess::Everyone);
        $post = $this->postIn($group);
        DB::table('timeline_posts')->where('id', $post->getKey())->update(['visibility' => Visibility::Open->value]);

        $this->assertFalse(TimelineAccess::canView(null, $post->fresh()));
    }

    public function test_the_community_unit_closes_the_post(): void
    {
        $group = $this->community(TopicReadAccess::Everyone);
        $post = $this->postIn($group);
        $member = $this->joined($group);

        $this->switchOff(Feature::Group);

        $this->assertFalse(TimelineAccess::canView($member, $post));
    }

    // Blocks and the author ----------------------------------------------------

    public function test_an_author_blocking_the_viewer_hides_their_community_post(): void
    {
        $group = $this->community(TopicReadAccess::Everyone);
        $author = $this->joined($group);
        $viewer = $this->joined($group);
        $post = $this->postIn($group, $author);

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
        $group = $this->community(TopicReadAccess::Everyone);
        $author = $this->joined($group);
        $post = $this->postIn($group, $author);

        GroupMember::where('group_id', $group->getKey())
            ->where('member_id', $author->getKey())->delete();

        $this->assertTrue(TimelineAccess::canView($this->joined($group), $post->fresh()));
    }

    // Posting ------------------------------------------------------------------

    public function test_only_members_may_post_even_when_everyone_may_read(): void
    {
        $group = $this->community(TopicReadAccess::Everyone);
        $member = $this->joined($group);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTimelineAccess::canViewTimeline($group, $stranger));
        $this->assertFalse(CommunityTimelineAccess::canPost($group, $stranger));
        $this->assertTrue(CommunityTimelineAccess::canPost($group, $member));
    }

    public function test_an_admins_only_board_does_not_silence_the_timeline(): void
    {
        // topic_post_authority gates the board, not this: a plain member still posts here.
        $group = $this->community(TopicReadAccess::Everyone, TopicPostAuthority::AdminsOnly);

        $this->assertTrue(CommunityTimelineAccess::canPost($group, $this->joined($group)));
    }

    private function switchOff(Feature $feature): void
    {
        $this->setSnsSetting($feature->settingKey(), false);
        $this->freshRequestState();
    }

    private function community(TopicReadAccess $read, ?TopicPostAuthority $post = null): Group
    {
        return Group::factory()->create(array_filter([
            'topic_read_access' => $read,
            'topic_post_authority' => $post,
        ]));
    }

    private function joined(Group $group, GroupRole $role = GroupRole::Member): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function postIn(Group $group, ?Member $author = null): TimelinePost
    {
        return TimelinePost::factory()->inGroup($group)->create([
            'member_id' => ($author ?? $this->joined($group))->getKey(),
        ]);
    }
}
