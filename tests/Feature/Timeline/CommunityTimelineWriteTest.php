<?php

namespace Tests\Feature\Timeline;

use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\GroupRole;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\Exceptions\NotGroupMember;
use App\Features\Timeline\Queries\MentionCandidates;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Writing into a community's timeline. The gate and the fixed visibility live in the action, so
 * they hold for any caller — the routes that reach it are added later.
 */
class CommunityTimelineWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_posts_into_the_community_at_members_visibility(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);

        // Open is asked for and ignored: a community post's audience is the community, so the
        // per-post ladder has nothing left to say and must not leave a web-public row behind.
        $post = app(CreateTimelinePost::class)(
            $author,
            new TimelinePostFormData('hello', Visibility::Open),
            null,
            $group,
        );

        $this->assertSame($group->getKey(), $post->community_id);
        $this->assertSame(Visibility::Members, $post->fresh()->visibility);
    }

    public function test_a_non_member_cannot_post_even_when_everyone_may_read(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);

        $this->expectException(NotGroupMember::class);

        app(CreateTimelinePost::class)(
            Member::factory()->create(),
            new TimelinePostFormData('hello', Visibility::Members),
            null,
            $group,
        );
    }

    public function test_an_admins_only_board_does_not_stop_a_member_posting(): void
    {
        // topic_post_authority gates the board, not the timeline.
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $author = $this->joined($group);

        $post = app(CreateTimelinePost::class)(
            $author,
            new TimelinePostFormData('hello', Visibility::Members),
            null,
            $group,
        );

        $this->assertSame($group->getKey(), $post->community_id);
    }

    public function test_an_sns_wide_post_keeps_the_chosen_visibility(): void
    {
        $author = Member::factory()->create();

        $post = app(CreateTimelinePost::class)($author, new TimelinePostFormData('hello', Visibility::Friends));

        $this->assertNull($post->community_id);
        $this->assertSame(Visibility::Friends, $post->fresh()->visibility);
    }

    public function test_mention_candidates_inside_a_community_are_its_members(): void
    {
        $group = Group::factory()->create();
        $viewer = $this->joined($group);
        $fellow = $this->joined($group);
        $stranger = Member::factory()->create();

        $ids = (new MentionCandidates)($viewer, '', $group)->modelKeys();

        $this->assertContains($fellow->getKey(), $ids);
        $this->assertNotContains($stranger->getKey(), $ids);
        // Without a community the same viewer still sees the whole SNS.
        $this->assertContains($stranger->getKey(), (new MentionCandidates)($viewer, '')->modelKeys());
    }

    public function test_a_mention_of_a_non_member_is_dropped_on_submit(): void
    {
        // The picker cannot offer them, but a hand-built payload can still name them; the submit
        // predicate has to agree with the candidate predicate or the row would outlive the offer.
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $stranger = Member::factory()->create(['name' => 'Outsider']);

        $post = app(CreateTimelinePost::class)(
            $author,
            new TimelinePostFormData('hi @Outsider', Visibility::Members, [
                ['member_id' => $stranger->getKey(), 'offset' => 3, 'length' => 9],
            ]),
            null,
            $group,
        );

        $this->assertCount(0, $post->fresh()->mentions);
    }

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }
}
