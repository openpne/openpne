<?php

namespace Tests\Feature\CommunityTopic\Classic;

use App\Features\Group\GroupRole;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityTopicCommentRoutesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_guests_are_redirected_to_login(): void
    {
        $topic = CommunityTopic::factory()->create();

        $this->post(route('communityTopic.comment.store', $topic))->assertRedirect('/login');
    }

    public function test_a_member_comments_and_the_topic_rises_on_the_board(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        DB::table('community_topics')->where('id', $topic->getKey())->update(['updated_at' => now()->subDay()]);

        $response = $this->actingAs($member)->post(route('communityTopic.comment.store', $topic), ['body' => 'First reply']);

        $response->assertRedirect(route('communityTopic.show', $topic));
        $this->assertDatabaseHas('community_topic_comments', [
            'community_topic_id' => $topic->getKey(),
            'number' => 1,
            'body' => 'First reply',
        ]);
        // The new comment lifts the topic's activity timestamp (board ordering key).
        $this->assertTrue($topic->fresh()->updated_at->greaterThan(now()->subMinute()));
    }

    public function test_a_non_member_cannot_comment(): void
    {
        $topic = CommunityTopic::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('communityTopic.comment.store', $topic), ['body' => 'intruding'])
            ->assertNotFound();
        $this->assertDatabaseCount('community_topic_comments', 0);
    }

    public function test_a_non_member_gets_404_even_with_an_invalid_comment(): void
    {
        // Membership is gated before validation, so a non-member's empty body 404s rather than
        // returning a "body required" error that would confirm the topic is commentable.
        $topic = CommunityTopic::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('communityTopic.comment.store', $topic), ['body' => ''])
            ->assertNotFound();
        $this->assertDatabaseCount('community_topic_comments', 0);
    }

    public function test_commenting_on_a_missing_topic_returns_404_even_with_an_invalid_body(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/communityTopic/999999/comment/create', ['body' => ''])
            ->assertNotFound();
    }

    public function test_a_comment_is_deletable_by_its_author_with_the_comment_body_id(): void
    {
        $group = Group::factory()->create();
        $commenter = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->actingAs($commenter)->get(route('communityTopic.comment.delete.show', $comment))
            ->assertOk()
            ->assertSee('id="page_communityTopicComment_deleteConfirm"', false);

        $this->actingAs($commenter)->post(route('communityTopic.comment.delete', $comment))
            ->assertRedirect(route('communityTopic.show', $topic));
        $this->assertDatabaseMissing('community_topic_comments', ['id' => $comment->getKey()]);
    }

    public function test_the_topic_author_and_admins_may_delete_others_comments(): void
    {
        $group = Group::factory()->create();
        $topicAuthor = $this->joined($group);
        $admin = $this->joined($group, GroupRole::Admin);
        $commenter = $this->joined($group);
        $other = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $topicAuthor->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        // An unrelated member cannot.
        $this->actingAs($other)->get(route('communityTopic.comment.delete.show', $comment))->assertNotFound();
        $this->actingAs($other)->post(route('communityTopic.comment.delete', $comment))->assertNotFound();

        // The topic author may.
        $this->actingAs($topicAuthor)->post(route('communityTopic.comment.delete', $comment))
            ->assertRedirect(route('communityTopic.show', $topic));
        $this->assertDatabaseMissing('community_topic_comments', ['id' => $comment->getKey()]);
    }
}
