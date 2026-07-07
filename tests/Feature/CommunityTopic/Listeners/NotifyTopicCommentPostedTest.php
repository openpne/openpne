<?php

declare(strict_types=1);

namespace Tests\Feature\CommunityTopic\Listeners;

use App\Features\CommunityTopic\Actions\CreateTopicComment;
use App\Features\CommunityTopic\Events\TopicCommentPosted;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Listeners\CommunityTopic\NotifyTopicCommentPosted;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\CommunityTopic\TopicCommentedNotification;
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
        $community = Community::factory()->create();
        [$author, $earlier, $commenter] = $this->members($community, 3);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
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
        $community = Community::factory()->create();
        [$author, $commenter] = $this->members($community, 2);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($topic, $author);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertSentToTimes($author, TopicCommentedNotification::class, 1);
    }

    public function test_skips_a_co_commenter_who_lost_board_access(): void
    {
        Notification::fake();
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        [$author, $commenter] = $this->members($community, 2);
        $outsider = Member::factory()->create(); // commented while a member, since left
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($topic, $outsider);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertNotSentTo($outsider, TopicCommentedNotification::class);
        Notification::assertSentTo($author, TopicCommentedNotification::class);
    }

    public function test_skips_recipients_blocked_against_the_commenter(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        [$author, $commenter] = $this->members($community, 2);
        DB::table('member_blocks')->insert(['blocker_id' => $author->getKey(), 'blocked_id' => $commenter->getKey()]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $comment = $this->comment($topic, $commenter);

        $this->handle($topic, $comment, $commenter);

        Notification::assertNotSentTo($author, TopicCommentedNotification::class);
    }

    public function test_reply_opt_out_drops_mail_but_keeps_the_database_record(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        [$author, $commenter] = $this->members($community, 2);
        $author->setNotificationSetting(NotificationKind::CommunityTopicReplyNewPost, NotificationChannel::Mail, false);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
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
        $community = Community::factory()->create();
        [$author, $commenter] = $this->members($community, 2);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        app(CreateTopicComment::class)($commenter, $topic, 'hello');

        Notification::assertSentTo($author, TopicCommentedNotification::class);
    }

    /** @return list<Member> community members */
    private function members(Community $community, int $count): array
    {
        $members = Member::factory()->count($count)->create()->all();
        foreach ($members as $member) {
            CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
        }

        return $members;
    }

    private function handle(CommunityTopic $topic, CommunityTopicComment $comment, Member $commenter): void
    {
        app(NotifyTopicCommentPosted::class)->handle(new TopicCommentPosted($topic, $comment, $commenter));
    }

    /** Seeds a comment row directly — the action would dispatch the event a second time. */
    private function comment(CommunityTopic $topic, Member $author): CommunityTopicComment
    {
        return $topic->comments()->create([
            'member_id' => $author->getKey(),
            'number' => (int) $topic->comments()->max('number') + 1,
            'body' => 'a comment',
        ]);
    }
}
