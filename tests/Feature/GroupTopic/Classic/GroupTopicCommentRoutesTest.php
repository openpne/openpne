<?php

namespace Tests\Feature\GroupTopic\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupTopicCommentRoutesTest extends TestCase
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

    public function test_a_member_comments_and_the_topic_rises_on_the_board(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        DB::table('group_topics')->where('id', $topic->getKey())->update(['updated_at' => now()->subDay(), 'bumped_at' => now()->subDay()]);

        $response = $this->actingAs($member)->post(route('group.topics.comment.store', $topic), ['body' => 'First reply']);

        $response->assertRedirect(route('group.topics.show', $topic));
        $this->assertDatabaseHas('group_topic_comments', [
            'group_topic_id' => $topic->getKey(),
            'number' => 1,
            'body' => 'First reply',
        ]);
        // The new comment lifts the topic's bumped_at and leaves updated_at alone.
        $this->assertTrue($topic->fresh()->bumped_at->greaterThan(now()->subMinute()));
        $this->assertTrue($topic->fresh()->updated_at->lessThan(now()->subHour()));
    }

    public function test_a_non_member_cannot_comment(): void
    {
        $topic = GroupTopic::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('group.topics.comment.store', $topic), ['body' => 'intruding'])
            ->assertNotFound();
        $this->assertDatabaseCount('group_topic_comments', 0);
    }

    public function test_a_non_member_gets_404_even_with_an_invalid_comment(): void
    {
        // Membership is gated before validation, so a non-member's empty body 404s rather than
        // returning a "body required" error that would confirm the topic is commentable.
        $topic = GroupTopic::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('group.topics.comment.store', $topic), ['body' => ''])
            ->assertNotFound();
        $this->assertDatabaseCount('group_topic_comments', 0);
    }

    public function test_commenting_on_a_missing_topic_returns_404_even_with_an_invalid_body(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/topics/999999/comments', ['body' => ''])
            ->assertNotFound();
    }

    public function test_a_comment_is_deletable_by_its_author_with_the_comment_body_id(): void
    {
        $group = Group::factory()->create();
        $commenter = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $comment = GroupTopicComment::factory()->create([
            'group_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->actingAs($commenter)->get(route('group.topics.comment.delete.show', $comment))
            ->assertOk()
            ->assertSee('id="page_communityTopicComment_deleteConfirm"', false)
            ->assertSee('<a href="'.route('group.topics.show', $topic).'" data-history-back>Back to previous page</a>', false)
            ->assertDontSee('>Back</a>', false);

        $this->actingAs($commenter)->post(route('group.topics.comment.delete', $comment))
            ->assertRedirect(route('group.topics.show', $topic));
        $this->assertDatabaseMissing('group_topic_comments', ['id' => $comment->getKey()]);
    }

    /** OpenPNE 3 communityTopicComment/_list.php: XDateTimeJaBr in the dt, the pager navigation above and below. */
    public function test_the_comment_list_stacks_its_dates_and_repeats_the_pager_under_the_comments(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        foreach (range(1, 21) as $number) {
            GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'number' => $number, 'created_at' => '2026-03-04 12:34:00']);
        }

        $content = (string) $this->actingAs($member)->withSession(['locale' => 'ja'])->get(route('group.topics.show', $topic))->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, '<p class="number">'));
        $this->assertStringContainsString('<dt>2026年<br />03月04日<br />12:34</dt>', $content);
    }

    public function test_the_topic_author_and_admins_may_delete_others_comments(): void
    {
        $group = Group::factory()->create();
        $topicAuthor = $this->joined($group);
        $admin = $this->joined($group, GroupRole::Admin);
        $commenter = $this->joined($group);
        $other = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $topicAuthor->getKey()]);
        $comment = GroupTopicComment::factory()->create([
            'group_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        // An unrelated member cannot.
        $this->actingAs($other)->get(route('group.topics.comment.delete.show', $comment))->assertNotFound();
        $this->actingAs($other)->post(route('group.topics.comment.delete', $comment))->assertNotFound();

        // The topic author may.
        $this->actingAs($topicAuthor)->post(route('group.topics.comment.delete', $comment))
            ->assertRedirect(route('group.topics.show', $topic));
        $this->assertDatabaseMissing('group_topic_comments', ['id' => $comment->getKey()]);
    }
}
