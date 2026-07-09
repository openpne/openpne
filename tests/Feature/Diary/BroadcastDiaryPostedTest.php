<?php

declare(strict_types=1);

namespace Tests\Feature\Diary;

use App\Features\Diary\Queries\DiaryPostedRecipients;
use App\Jobs\BroadcastDiaryPosted;
use App\Models\Diary;
use App\Models\Member;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The new-diary broadcast: audience by visibility (Open/Members = everyone, Friends = the author's
 * friends, Private = nobody), minus the author / banned / blocked; each recipient's channels are the
 * OpenPNE 3 union of diary-new-post and (for friends) diary-new-post-only-friends, absent-means-on.
 */
class BroadcastDiaryPostedTest extends TestCase
{
    use RefreshDatabase;

    private function broadcast(Diary $diary): void
    {
        (new BroadcastDiaryPosted((int) $diary->getKey()))->handle(app(DiaryPostedRecipients::class));
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    public function test_a_members_diary_notifies_every_other_active_member(): void
    {
        Notification::fake();
        [$author, $reader, $other] = Member::factory()->count(3)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->broadcast($diary);

        foreach ([$reader, $other] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                DiaryPostedNotification::class,
                fn (DiaryPostedNotification $n, array $channels) => $n->diary->is($diary)
                    && $channels === ['mail', 'database'],
            );
        }
        // Never the author.
        Notification::assertNotSentTo($author, DiaryPostedNotification::class);
    }

    public function test_a_friends_diary_notifies_only_the_authors_friends(): void
    {
        Notification::fake();
        [$author, $friend, $stranger] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($author, $friend);
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Friends]);

        $this->broadcast($diary);

        Notification::assertSentTo($friend, DiaryPostedNotification::class);
        Notification::assertNotSentTo($stranger, DiaryPostedNotification::class);
    }

    public function test_a_private_diary_notifies_nobody(): void
    {
        Notification::fake();
        [$author, $other] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Private]);

        $this->broadcast($diary);

        Notification::assertNothingSent();
    }

    public function test_banned_and_blocked_members_are_excluded(): void
    {
        Notification::fake();
        [$author, $banned, $blocked] = Member::factory()->count(3)->create()->all();
        $banned->forceFill(['is_login_rejected' => true])->save();
        DB::table('member_blocks')->insert(['blocker_id' => $blocked->getKey(), 'blocked_id' => $author->getKey()]);
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->broadcast($diary);

        Notification::assertNotSentTo($banned, DiaryPostedNotification::class);
        Notification::assertNotSentTo($blocked, DiaryPostedNotification::class);
    }

    public function test_opting_out_of_diary_new_post_drops_the_channel(): void
    {
        Notification::fake();
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $reader->setNotificationSetting(NotificationKind::DiaryNewPost, NotificationChannel::Mail, false);
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->broadcast($diary);

        // Not a friend, so the friends-only variant cannot re-add mail — database only remains.
        Notification::assertSentTo(
            $reader,
            DiaryPostedNotification::class,
            fn (DiaryPostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_friends_only_variant_still_reaches_a_friend_who_turned_off_the_broad_kind(): void
    {
        Notification::fake();
        [$author, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($author, $friend);
        // Broad kind off on both channels; only the friends-only variant keeps the friend subscribed.
        $friend->setNotificationSetting(NotificationKind::DiaryNewPost, NotificationChannel::Mail, false);
        $friend->setNotificationSetting(NotificationKind::DiaryNewPost, NotificationChannel::Web, false);
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->broadcast($diary);

        Notification::assertSentTo(
            $friend,
            DiaryPostedNotification::class,
            fn (DiaryPostedNotification $n, array $channels) => $channels === ['mail', 'database'],
        );
    }

    public function test_both_kinds_off_drops_a_friend_entirely(): void
    {
        Notification::fake();
        [$author, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($author, $friend);
        foreach ([NotificationKind::DiaryNewPost, NotificationKind::DiaryNewPostOnlyFriends] as $kind) {
            foreach ([NotificationChannel::Mail, NotificationChannel::Web] as $channel) {
                $friend->setNotificationSetting($kind, $channel, false);
            }
        }
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->broadcast($diary);

        Notification::assertNotSentTo($friend, DiaryPostedNotification::class);
    }

    public function test_a_member_without_an_address_gets_the_feed_row_but_no_mail(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $noAddress = Member::factory()->create(['email' => null]);
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->broadcast($diary);

        Notification::assertSentTo(
            $noAddress,
            DiaryPostedNotification::class,
            fn (DiaryPostedNotification $n, array $channels) => $channels === ['database'],
        );
    }
}
