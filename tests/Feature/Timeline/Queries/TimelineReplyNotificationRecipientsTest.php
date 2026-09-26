<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\TimelineReplyNotificationRecipients;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\CommentReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class TimelineReplyNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    private function reply(TimelinePost $root, Member $by): TimelinePost
    {
        return TimelinePost::factory()->replyTo($root)->create(['member_id' => $by->getKey()]);
    }

    public function test_the_root_author_is_told_of_a_reply_and_the_other_repliers_of_a_related_one_once_each(): void
    {
        $owner = Member::factory()->create();
        $root = TimelinePost::factory()->create(['member_id' => $owner->getKey()]);
        $replier = Member::factory()->create();
        $other = Member::factory()->create();
        $this->reply($root, $other);
        $this->reply($root, $other);
        $this->reply($root, $owner);
        $this->reply($root, $replier);
        $reply = $this->reply($root, $replier);

        $recipients = app(TimelineReplyNotificationRecipients::class)($reply, $replier);

        $this->assertSame(
            [[$owner->getKey(), CommentReason::Reply], [$other->getKey(), CommentReason::Related]],
            $this->reasonsOf($recipients),
        );
    }

    public function test_members_already_told_by_a_mention_and_those_who_may_not_receive_are_left_out(): void
    {
        $owner = Member::factory()->create();
        $root = TimelinePost::factory()->create(['member_id' => $owner->getKey()]);
        $replier = Member::factory()->create();
        $mentioned = Member::factory()->create();
        $this->reply($root, $mentioned);
        $banned = $this->banned();
        $this->reply($root, $banned);
        $blocking = Member::factory()->create();
        $this->block($blocking, $replier);
        $this->reply($root, $blocking);
        $reply = $this->reply($root, $replier);

        $recipients = app(TimelineReplyNotificationRecipients::class)($reply, $replier, [$mentioned->getKey()]);
        $this->assertSame($this->keys($owner), $this->idsOf($recipients));

        $recipients = app(TimelineReplyNotificationRecipients::class)($reply, $replier, [$mentioned->getKey(), $owner->getKey()]);
        $this->assertSame([], $recipients);
    }

    public function test_a_top_level_post_is_a_reply_to_nobody(): void
    {
        $post = TimelinePost::factory()->create();

        $this->assertSame([], app(TimelineReplyNotificationRecipients::class)($post, $post->member));
    }
}
