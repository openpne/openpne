<?php

declare(strict_types=1);

namespace Tests\Feature\GroupEvent\Listeners;

use App\Features\GroupEvent\Actions\SubmitEventComment;
use App\Features\GroupEvent\Events\EventCommentPosted;
use App\Listeners\GroupEvent\NotifyEventCommentPosted;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\GroupEvent\EventCommentedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The event twin of the topic listener test: same recipient rules, so only the routing and the
 * merged submit entry point are pinned here — the shared conditions live in the topic suite.
 */
class NotifyEventCommentPostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_the_author_as_a_reply_and_a_co_commenter_as_related(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $earlier, $commenter] = $this->members($group, 3);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($event, $earlier);
        $comment = $this->comment($event, $commenter);

        $this->handle($event, $comment, $commenter);

        Notification::assertSentTo(
            $author,
            EventCommentedNotification::class,
            fn (EventCommentedNotification $notification, array $channels) => $notification->reason === CommentReason::Reply
                && $channels === ['mail', 'database'],
        );
        Notification::assertSentTo(
            $earlier,
            EventCommentedNotification::class,
            fn (EventCommentedNotification $notification) => $notification->reason === CommentReason::Related,
        );
        Notification::assertNotSentTo($commenter, EventCommentedNotification::class);
    }

    public function test_the_merged_rsvp_comment_submit_dispatches_through_auto_discovery(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $commenter] = $this->members($group, 2);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        app(SubmitEventComment::class)($commenter, $event, 'hello', [], toggleRoster: false);

        Notification::assertSentTo($author, EventCommentedNotification::class);
    }

    /** @return list<Member> community members */
    private function members(Group $group, int $count): array
    {
        $members = Member::factory()->count($count)->create()->all();
        foreach ($members as $member) {
            GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        }

        return $members;
    }

    private function handle(GroupEvent $event, GroupEventComment $comment, Member $commenter): void
    {
        app(NotifyEventCommentPosted::class)->handle(new EventCommentPosted($event, $comment, $commenter));
    }

    /** Seeds a comment row directly — the action would dispatch the event a second time. */
    private function comment(GroupEvent $event, Member $author): GroupEventComment
    {
        return $event->comments()->create([
            'member_id' => $author->getKey(),
            'number' => (int) $event->comments()->max('number') + 1,
            'body' => 'a comment',
        ]);
    }
}
