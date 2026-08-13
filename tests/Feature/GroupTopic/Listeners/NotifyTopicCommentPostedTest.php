<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTopic\Listeners;

use App\Features\GroupTopic\Actions\CreateTopicComment;
use App\Features\GroupTopic\Events\TopicCommentPosted;
use App\Features\GroupTopic\TopicReadAccess;
use App\Listeners\GroupTopic\NotifyTopicCommentPosted;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\GroupTopic\TopicCommentedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyTopicCommentPostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_the_author_as_a_reply_and_a_co_commenter_as_related(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $earlier, $commenter] = $this->members($group, 3);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($topic, $earlier);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertSentTo(
            $author,
            TopicCommentedNotification::class,
            fn (TopicCommentedNotification $notification, array $channels) => $notification->reason === CommentReason::Reply
                && $channels === ['mail', 'database'],
        );
        Notification::assertSentTo(
            $earlier,
            TopicCommentedNotification::class,
            fn (TopicCommentedNotification $notification) => $notification->reason === CommentReason::Related,
        );
        Notification::assertNotSentTo($commenter, TopicCommentedNotification::class);
    }

    public function test_the_author_who_also_commented_gets_one_reply_notification(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $commenter] = $this->members($group, 2);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($topic, $author);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertSentToTimes($author, TopicCommentedNotification::class, 1);
    }

    public function test_skips_a_co_commenter_who_lost_board_access(): void
    {
        Notification::fake();
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        [$author, $commenter] = $this->members($group, 2);
        $outsider = Member::factory()->create(); // commented while a member, since left
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($topic, $outsider);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertNotSentTo($outsider, TopicCommentedNotification::class);
        Notification::assertSentTo($author, TopicCommentedNotification::class);
    }

    public function test_skips_recipients_blocked_against_the_commenter(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $commenter] = $this->members($group, 2);
        DB::table('member_blocks')->insert(['blocker_id' => $author->getKey(), 'blocked_id' => $commenter->getKey()]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertNotSentTo($author, TopicCommentedNotification::class);
    }

    public function test_reply_opt_out_drops_mail_but_keeps_the_database_record(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $commenter] = $this->members($group, 2);
        $author->setNotificationSetting(NotificationKind::GroupTopicReplyNewPost, NotificationChannel::Mail, false);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertSentTo(
            $author,
            TopicCommentedNotification::class,
            fn (TopicCommentedNotification $notification, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_create_action_dispatches_through_auto_discovery(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $commenter] = $this->members($group, 2);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        app(CreateTopicComment::class)($commenter, $topic, 'hello');

        Notification::assertSentTo($author, TopicCommentedNotification::class);
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

    private function handle(GroupTopic $topic, GroupTopicComment $comment, Member $commenter): void
    {
        app(NotifyTopicCommentPosted::class)->handle(new TopicCommentPosted($topic, $comment, $commenter));
    }

    /** Seeds a comment row directly — the action would dispatch the event a second time. */
    private function comment(GroupTopic $topic, Member $author): GroupTopicComment
    {
        return $topic->comments()->create([
            'member_id' => $author->getKey(),
            'number' => (int) $topic->comments()->max('number') + 1,
            'body' => 'a comment',
        ]);
    }
}
