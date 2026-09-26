<?php

namespace Tests\Feature\GroupTopic\Queries;

use App\Features\GroupTopic\Queries\TopicCommentNotificationRecipients;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class TopicCommentNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    private int $number = 0;

    private function comment(GroupTopic $topic, Member $by): void
    {
        GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'member_id' => $by->getKey(), 'number' => ++$this->number]);
    }

    public function test_the_topic_author_is_told_of_a_reply_and_the_other_commenters_of_a_related_one_once_each(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $commenter = $this->joined($group);
        $other = $this->joined($group);
        $this->comment($topic, $other);
        $this->comment($topic, $other);
        $this->comment($topic, $author);
        $this->comment($topic, $commenter);

        $recipients = app(TopicCommentNotificationRecipients::class)($topic, $commenter);

        $this->assertSame(
            [[$author->getKey(), CommentReason::Reply], [$other->getKey(), CommentReason::Related]],
            $this->reasonsOf($recipients),
        );
    }

    public function test_the_author_commenting_on_their_own_topic_tells_only_the_others(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $other = $this->joined($group);
        $this->comment($topic, $other);

        $recipients = app(TopicCommentNotificationRecipients::class)($topic, $author);

        $this->assertSame([[$other->getKey(), CommentReason::Related]], $this->reasonsOf($recipients));
    }

    public function test_each_recipient_is_judged_as_of_now(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $left = Member::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $left->getKey()]);
        $commenter = $this->joined($group);
        $member = $this->joined($group);
        $this->comment($topic, $member);
        $gone = Member::factory()->create();
        $this->comment($topic, $gone);
        $banned = $this->joined($group, $this->banned());
        $this->comment($topic, $banned);
        $blocking = $this->joined($group);
        $this->block($blocking, $commenter);
        $this->comment($topic, $blocking);
        $shunned = $this->joined($group);
        $this->comment($topic, $shunned);
        $this->block($commenter, $shunned);

        $recipients = app(TopicCommentNotificationRecipients::class)($topic, $commenter);

        $this->assertSame([[$member->getKey(), CommentReason::Related]], $this->reasonsOf($recipients));
    }

    public function test_on_a_board_everyone_may_read_a_commenter_who_has_since_left_the_group_is_still_told(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $commenter = $this->joined($group);
        $left = Member::factory()->create();
        $this->comment($topic, $left);

        $recipients = app(TopicCommentNotificationRecipients::class)($topic, $commenter);

        $this->assertSame(
            [[$author->getKey(), CommentReason::Reply], [$left->getKey(), CommentReason::Related]],
            $this->reasonsOf($recipients),
        );
    }
}
