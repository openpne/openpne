<?php

declare(strict_types=1);

namespace Tests\Feature\Diary\Listeners;

use App\Features\Diary\Actions\CreateComment;
use App\Features\Diary\Events\DiaryCommentPosted;
use App\Listeners\Diary\NotifyDiaryCommentPosted;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyDiaryCommentPostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_the_owner_as_a_reply(): void
    {
        Notification::fake();
        [$owner, $commenter] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertSentTo(
            $owner,
            DiaryCommentedNotification::class,
            fn (DiaryCommentedNotification $notification, array $channels) => $notification->reason === CommentReason::Reply
                && $notification->commenter->is($commenter)
                && $channels === ['mail', 'database'],
        );
    }

    public function test_notifies_a_co_commenter_as_related_and_never_the_commenter(): void
    {
        Notification::fake();
        [$owner, $earlier, $commenter] = Member::factory()->count(3)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $this->comment($diary, $earlier);
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertSentTo(
            $earlier,
            DiaryCommentedNotification::class,
            fn (DiaryCommentedNotification $notification) => $notification->reason === CommentReason::Related,
        );
        Notification::assertNotSentTo($commenter, DiaryCommentedNotification::class);
    }

    public function test_the_owner_who_also_commented_gets_one_reply_notification(): void
    {
        Notification::fake();
        [$owner, $commenter] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $this->comment($diary, $owner); // the owner joined their own thread
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertSentToTimes($owner, DiaryCommentedNotification::class, 1);
        Notification::assertSentTo(
            $owner,
            DiaryCommentedNotification::class,
            fn (DiaryCommentedNotification $notification) => $notification->reason === CommentReason::Reply,
        );
    }

    public function test_a_self_comment_notifies_nobody(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = $this->comment($diary, $owner);

        $this->handle($diary, $comment, $owner);

        Notification::assertNothingSent();
    }

    public function test_skips_recipients_the_commenter_is_blocked_against(): void
    {
        Notification::fake();
        [$owner, $commenter] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $commenter->getKey()]);
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertNotSentTo($owner, DiaryCommentedNotification::class);
    }

    public function test_skips_a_co_commenter_who_can_no_longer_view_the_diary(): void
    {
        Notification::fake();
        [$owner, $earlier, $commenter] = Member::factory()->count(3)->create()->all();
        // Friends-only diary: the owner's friends commented, then one unfriended (here: never was).
        $diary = Diary::factory()->friends()->create(['member_id' => $owner->getKey()]);
        $this->comment($diary, $earlier);
        $this->makeFriends($owner, $commenter);
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertNotSentTo($earlier, DiaryCommentedNotification::class);
        Notification::assertSentTo($owner, DiaryCommentedNotification::class);
    }

    public function test_skips_a_banned_recipient(): void
    {
        Notification::fake();
        [$owner, $commenter] = Member::factory()->count(2)->create()->all();
        $owner->forceFill(['is_login_rejected' => true])->save();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertNotSentTo($owner, DiaryCommentedNotification::class);
    }

    public function test_a_withdrawn_co_commenters_rows_drop_out(): void
    {
        Notification::fake();
        [$owner, $withdrawn, $commenter] = Member::factory()->count(3)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $this->comment($diary, $withdrawn);
        $withdrawn->delete(); // diary_comments.member_id goes NULL
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertSentToTimes($owner, DiaryCommentedNotification::class, 1);
    }

    public function test_reply_opt_out_drops_mail_but_keeps_the_database_record(): void
    {
        Notification::fake();
        [$owner, $commenter] = Member::factory()->count(2)->create()->all();
        $owner->setNotificationSetting(NotificationKind::DiaryReplyPost, NotificationChannel::Mail, false);
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = $this->comment($diary, $commenter);

        $this->handle($diary, $comment, $commenter);

        Notification::assertSentTo(
            $owner,
            DiaryCommentedNotification::class,
            fn (DiaryCommentedNotification $notification, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_create_comment_action_dispatches_through_auto_discovery(): void
    {
        Notification::fake();
        [$owner, $commenter] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        app(CreateComment::class)($commenter, $diary, 'hello');

        Notification::assertSentTo($owner, DiaryCommentedNotification::class);
    }

    private function handle(Diary $diary, DiaryComment $comment, Member $commenter): void
    {
        app(NotifyDiaryCommentPosted::class)->handle(new DiaryCommentPosted($diary, $comment, $commenter));
    }

    /** Seeds a comment row directly — the action would dispatch the event a second time. */
    private function comment(Diary $diary, Member $author): DiaryComment
    {
        return $diary->comments()->create([
            'member_id' => $author->getKey(),
            'number' => (int) $diary->comments()->max('number') + 1,
            'body' => 'a comment',
        ]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
