<?php

declare(strict_types=1);

namespace Tests\Feature\Group;

use App\Features\Group\GroupRole;
use App\Jobs\BroadcastEventPosted;
use App\Jobs\BroadcastTopicPosted;
use App\Mail\Template\MailTemplate;
use App\Models\CommunityEvent;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Notifications\CommunityEvent\EventPostedNotification;
use App\Notifications\GroupTopic\TopicPostedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The new-topic / new-event broadcast to a community's confirmed members, minus the author / banned /
 * blocked, gated by the single new-post kind (and the shared group-posting template for the mail
 * leg). Topic is covered thoroughly; event shares the fan-out so it gets the smoke path.
 */
class BroadcastGroupNewPostTest extends TestCase
{
    use RefreshDatabase;

    private function member(Group $group, ?Member $member = null, GroupRole $role = GroupRole::Member): Member
    {
        $member ??= Member::factory()->create();
        $group->members()->create(['member_id' => $member->getKey(), 'role' => $role]);

        return $member;
    }

    private function broadcastTopic(GroupTopic $topic): void
    {
        app()->call([new BroadcastTopicPosted((int) $topic->getKey()), 'handle']);
    }

    public function test_a_new_topic_notifies_confirmed_members_but_not_the_author(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->member($group, role: GroupRole::Admin);
        $reader = $this->member($group);
        $stranger = Member::factory()->create(); // not a community member
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->broadcastTopic($topic);

        Notification::assertSentTo(
            $reader,
            TopicPostedNotification::class,
            fn (TopicPostedNotification $n, array $channels) => $n->topic->is($topic) && $channels === ['mail', 'database'],
        );
        Notification::assertNotSentTo($author, TopicPostedNotification::class);
        Notification::assertNotSentTo($stranger, TopicPostedNotification::class);
    }

    public function test_banned_and_blocked_members_are_excluded(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->member($group);
        $banned = $this->member($group);
        $banned->forceFill(['is_login_rejected' => true])->save();
        $blocked = $this->member($group);
        DB::table('member_blocks')->insert(['blocker_id' => $author->getKey(), 'blocked_id' => $blocked->getKey()]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->broadcastTopic($topic);

        Notification::assertNotSentTo($banned, TopicPostedNotification::class);
        Notification::assertNotSentTo($blocked, TopicPostedNotification::class);
    }

    public function test_opting_out_of_the_kind_drops_the_channel(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->member($group);
        $reader = $this->member($group);
        $reader->setNotificationSetting(NotificationKind::GroupTopicNewPost, NotificationChannel::Mail, false);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->broadcastTopic($topic);

        Notification::assertSentTo(
            $reader,
            TopicPostedNotification::class,
            fn (TopicPostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_a_disabled_template_drops_the_mail_leg_for_everyone(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->member($group);
        $reader = $this->member($group);
        // group-posting is configurable; an admin turned it off globally.
        DB::table('mail_templates')->insert(['key' => MailTemplate::GroupPostingNotified->value, 'is_enabled' => false]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->broadcastTopic($topic);

        Notification::assertSentTo(
            $reader,
            TopicPostedNotification::class,
            fn (TopicPostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_a_member_without_an_address_gets_the_feed_row_but_no_mail(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->member($group);
        $noAddress = $this->member($group, Member::factory()->create(['email' => null]));
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->broadcastTopic($topic);

        Notification::assertSentTo(
            $noAddress,
            TopicPostedNotification::class,
            fn (TopicPostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_a_new_event_notifies_confirmed_members(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->member($group);
        $reader = $this->member($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        app()->call([new BroadcastEventPosted((int) $event->getKey()), 'handle']);

        Notification::assertSentTo($reader, EventPostedNotification::class);
        Notification::assertNotSentTo($author, EventPostedNotification::class);
    }
}
