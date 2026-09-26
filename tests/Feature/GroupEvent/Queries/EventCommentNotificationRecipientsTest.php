<?php

namespace Tests\Feature\GroupEvent\Queries;

use App\Features\GroupEvent\Queries\EventCommentNotificationRecipients;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class EventCommentNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    private int $number = 0;

    private function comment(GroupEvent $event, Member $by): void
    {
        GroupEventComment::factory()->create(['group_event_id' => $event->getKey(), 'member_id' => $by->getKey(), 'number' => ++$this->number]);
    }

    public function test_the_event_author_is_told_of_a_reply_and_the_other_commenters_of_a_related_one_once_each(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $commenter = $this->joined($group);
        $other = $this->joined($group);
        $this->comment($event, $other);
        $this->comment($event, $other);
        $this->comment($event, $author);
        $this->comment($event, $commenter);

        $recipients = app(EventCommentNotificationRecipients::class)($event, $commenter);

        $this->assertSame(
            [[$author->getKey(), CommentReason::Reply], [$other->getKey(), CommentReason::Related]],
            $this->reasonsOf($recipients),
        );
    }

    public function test_the_author_commenting_on_their_own_event_tells_only_the_others(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $other = $this->joined($group);
        $this->comment($event, $other);

        $recipients = app(EventCommentNotificationRecipients::class)($event, $author);

        $this->assertSame([[$other->getKey(), CommentReason::Related]], $this->reasonsOf($recipients));
    }

    public function test_each_recipient_is_judged_as_of_now(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $left = Member::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $left->getKey()]);
        $commenter = $this->joined($group);
        $member = $this->joined($group);
        $this->comment($event, $member);
        $gone = Member::factory()->create();
        $this->comment($event, $gone);
        $banned = $this->joined($group, $this->banned());
        $this->comment($event, $banned);
        $blocking = $this->joined($group);
        $this->block($blocking, $commenter);
        $this->comment($event, $blocking);
        $shunned = $this->joined($group);
        $this->comment($event, $shunned);
        $this->block($commenter, $shunned);

        $recipients = app(EventCommentNotificationRecipients::class)($event, $commenter);

        $this->assertSame([[$member->getKey(), CommentReason::Related]], $this->reasonsOf($recipients));
    }

    public function test_on_a_board_everyone_may_read_a_commenter_who_never_joined_is_still_told(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $commenter = $this->joined($group);
        $passerby = Member::factory()->create();
        $this->comment($event, $passerby);

        $recipients = app(EventCommentNotificationRecipients::class)($event, $commenter);

        $this->assertSame(
            [[$author->getKey(), CommentReason::Reply], [$passerby->getKey(), CommentReason::Related]],
            $this->reasonsOf($recipients),
        );
    }
}
