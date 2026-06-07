<?php

namespace Tests\Feature\CommunityTopic\Actions;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\Actions\CreateTopic;
use App\Features\CommunityTopic\Actions\CreateTopicComment;
use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Features\CommunityTopic\Actions\DeleteTopicComment;
use App\Features\CommunityTopic\Actions\UpdateTopic;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopicActionsTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Community $community, CommunityRole $role): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function assertFails(callable $run, CommunityTopicActionFailure $reason): void
    {
        try {
            $run();
            $this->fail("expected CommunityTopicActionException [{$reason->value}]");
        } catch (CommunityTopicActionException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_create_topic_sets_the_author_and_activity_timestamp(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);

        $topic = app(CreateTopic::class)($author, $community, new CommunityTopicFormData('Welcome', 'Say hi here.'));

        $this->assertSame($community->getKey(), $topic->community_id);
        $this->assertSame($author->getKey(), $topic->member_id);
        $this->assertNotNull($topic->topic_updated_at);
    }

    public function test_create_topic_is_blocked_when_posting_is_admin_only(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertFails(
            fn () => app(CreateTopic::class)($member, $community, new CommunityTopicFormData('No', 'Nope.')),
            CommunityTopicActionFailure::CannotPost,
        );
    }

    public function test_update_topic_bumps_timestamps_only_on_a_content_change(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        DB::table('community_topics')->where('id', $topic->getKey())->update([
            'updated_at' => now()->subDay(),
            'topic_updated_at' => now()->subDay(),
        ]);

        // No-op edit (same content) does not touch the timestamps.
        app(UpdateTopic::class)($author, $topic->fresh(), new CommunityTopicFormData($topic->name, $topic->body));
        $this->assertTrue($topic->fresh()->updated_at->lessThan(now()->subHour()));

        // A real edit bumps both updated_at (board key) and topic_updated_at.
        app(UpdateTopic::class)($author, $topic->fresh(), new CommunityTopicFormData('Edited', $topic->body));
        $fresh = $topic->fresh();
        $this->assertSame('Edited', $fresh->name);
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->topic_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_update_topic_is_blocked_for_a_non_author_non_admin(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $other = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->assertFails(
            fn () => app(UpdateTopic::class)($other, $topic, new CommunityTopicFormData('Hijack', 'No.')),
            CommunityTopicActionFailure::CannotEdit,
        );
    }

    public function test_delete_topic_removes_it_and_cascades_comments(): void
    {
        $community = Community::factory()->create();
        $admin = $this->joined($community, CommunityRole::Admin);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);
        CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);

        (new DeleteTopic)($admin, $topic);

        $this->assertDatabaseMissing('community_topics', ['id' => $topic->getKey()]);
        $this->assertSame(0, CommunityTopicComment::query()->count());
    }

    public function test_comments_are_numbered_per_topic_and_lift_the_board_timestamp(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        DB::table('community_topics')->where('id', $topic->getKey())->update(['updated_at' => now()->subDay()]);

        $first = app(CreateTopicComment::class)($author, $topic, 'one');
        $second = app(CreateTopicComment::class)($author, $topic, 'two');
        $third = app(CreateTopicComment::class)($author, $topic, 'three');

        $this->assertSame([1, 2, 3], [$first->number, $second->number, $third->number]);
        // A new comment lifts the topic on the board.
        $this->assertTrue($topic->fresh()->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($topic->fresh()->topic_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_commenting_is_blocked_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->assertFails(
            fn () => app(CreateTopicComment::class)($stranger, $topic, 'intruding'),
            CommunityTopicActionFailure::CannotComment,
        );
    }

    public function test_delete_comment_is_blocked_for_an_unrelated_member(): void
    {
        $community = Community::factory()->create();
        $commenter = $this->joined($community, CommunityRole::Member);
        $other = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertFails(
            fn () => (new DeleteTopicComment)($other, $comment),
            CommunityTopicActionFailure::CannotDeleteComment,
        );

        (new DeleteTopicComment)($commenter, $comment);
        $this->assertDatabaseMissing('community_topic_comments', ['id' => $comment->getKey()]);
    }
}
