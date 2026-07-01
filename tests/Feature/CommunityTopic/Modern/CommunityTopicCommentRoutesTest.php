<?php

namespace Tests\Feature\CommunityTopic\Modern;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTopicCommentRoutesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $comment = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey(), 'number' => 1]);

        $this->post(route('communityTopic.modern.comment.store', $topic))
            ->assertRedirect('/login');
        $this->post(route('communityTopic.modern.comment.delete', $comment))
            ->assertRedirect('/login');
    }

    public function test_modern_comment_store_creates_a_comment_and_redirects_to_modern_show(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->post(route('communityTopic.modern.comment.store', $topic), ['body' => 'A modern reply'])
            ->assertRedirect(route('communityTopic.modern.show', $topic));

        $this->assertDatabaseHas('community_topic_comments', [
            'community_topic_id' => $topic->getKey(),
            'member_id' => $member->getKey(),
            'body' => 'A modern reply',
        ]);
    }

    public function test_modern_comment_store_returns_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post(route('communityTopic.modern.comment.store', $topic), ['body' => 'intruding'])
            ->assertNotFound();
        $this->assertDatabaseCount('community_topic_comments', 0);
    }

    public function test_modern_comment_delete_removes_the_comment_and_redirects_to_modern_show(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $member->getKey(),
            'number' => 1,
        ]);

        $this->actingAs($member)
            ->post(route('communityTopic.modern.comment.delete', $comment))
            ->assertRedirect(route('communityTopic.modern.show', $topic));

        $this->assertDatabaseMissing('community_topic_comments', ['id' => $comment->getKey()]);
    }

    public function test_modern_comment_delete_returns_404_for_an_unauthorized_member(): void
    {
        // A community member who is neither the comment's author nor a topic editor cannot delete it.
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
            'number' => 1,
        ]);
        $other = $this->joined($community);

        $this->actingAs($other)
            ->post(route('communityTopic.modern.comment.delete', $comment))
            ->assertNotFound();
        $this->assertDatabaseHas('community_topic_comments', ['id' => $comment->getKey()]);
    }
}
