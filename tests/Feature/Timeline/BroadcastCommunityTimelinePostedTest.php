<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use App\Features\Group\GroupNewPostFanout;
use App\Features\Group\GroupRole;
use App\Features\Group\Queries\GroupNewPostRecipients;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Jobs\BroadcastCommunityTimelinePosted;
use App\Jobs\BroadcastTimelinePosted;
use App\Listeners\Timeline\NotifyTimelinePosted;
use App\Mail\Template\MailTemplateService;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Notifications\Timeline\TimelineCommunityPostedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A community post announces itself to its community, once. The two fan-outs are exclusive: an
 * everyone-readable community would otherwise reach the same members through the SNS-wide job as
 * well, under a kind they never chose it by.
 */
class BroadcastCommunityTimelinePostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_community_post_dispatches_only_the_community_fanout(): void
    {
        Bus::fake();
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $post = TimelinePost::factory()->inGroup($group)->create();

        (new NotifyTimelinePosted)->handle(new TimelinePostPosted($post, $post->member, []));

        Bus::assertDispatched(BroadcastCommunityTimelinePosted::class);
        Bus::assertNotDispatched(BroadcastTimelinePosted::class);
    }

    public function test_an_sns_wide_post_dispatches_only_the_sns_wide_fanout(): void
    {
        Bus::fake();
        $post = TimelinePost::factory()->create();

        (new NotifyTimelinePosted)->handle(new TimelinePostPosted($post, $post->member, []));

        Bus::assertDispatched(BroadcastTimelinePosted::class);
        Bus::assertNotDispatched(BroadcastCommunityTimelinePosted::class);
    }

    public function test_the_audience_is_the_community_not_everyone_who_may_read_it(): void
    {
        Notification::fake();
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $author = $this->joined($group);
        $fellow = $this->joined($group);
        $outsider = Member::factory()->create();

        $this->broadcast($this->postIn($group, $author));

        Notification::assertSentTo($fellow, TimelineCommunityPostedNotification::class);
        Notification::assertNotSentTo($outsider, TimelineCommunityPostedNotification::class);
        Notification::assertNotSentTo($author, TimelineCommunityPostedNotification::class);
    }

    public function test_the_community_kind_carries_the_opt_out(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $silenced = $this->joined($group);
        $silenced->setNotificationSetting(NotificationKind::TimelineNewPostCommunity, NotificationChannel::Web, false);
        $silenced->setNotificationSetting(NotificationKind::TimelineNewPostCommunity, NotificationChannel::Mail, false);

        // Silencing the SNS-wide kind must not silence this one, and vice versa.
        $unrelated = $this->joined($group);
        $unrelated->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Web, false);
        $unrelated->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Mail, false);

        $this->broadcast($this->postIn($group, $author));

        Notification::assertNotSentTo($silenced, TimelineCommunityPostedNotification::class);
        Notification::assertSentTo($unrelated, TimelineCommunityPostedNotification::class);
    }

    public function test_a_mentioned_member_is_not_told_twice(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $mentioned = $this->joined($group);

        $this->broadcast($this->postIn($group, $author), [$mentioned->getKey()]);

        Notification::assertNotSentTo($mentioned, TimelineCommunityPostedNotification::class);
    }

    public function test_a_member_who_left_stops_receiving_the_thread(): void
    {
        // The eligibility predicate is re-read at delivery, so a queued notification does not carry
        // a community's body to someone who has since left it.
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $author = $this->joined($group);
        $former = $this->joined($group);
        $post = $this->postIn($group, $author);

        $notification = new TimelineCommunityPostedNotification($post, $author, ['database']);
        $this->assertTrue($notification->shouldSend($former, 'database'));

        GroupMember::where('group_id', $group->getKey())
            ->where('member_id', $former->getKey())->delete();

        $this->assertFalse($notification->shouldSend($former->fresh(), 'database'));
    }

    private function broadcast(TimelinePost $post, array $mentionedMemberIds = []): void
    {
        (new BroadcastCommunityTimelinePosted((int) $post->getKey(), $mentionedMemberIds))
            ->handle(app(GroupNewPostFanout::class), app(GroupNewPostRecipients::class), app(MailTemplateService::class));
    }

    /**
     * Built by factory, not through CreateTimelinePost: the action dispatches the event, whose
     * listener runs this very job on the sync queue, and a second delivery from the run under test
     * would hide whichever exclusion it was meant to prove.
     */
    private function postIn(Group $group, Member $author): TimelinePost
    {
        return TimelinePost::factory()->inGroup($group)->create(['member_id' => $author->getKey()]);
    }

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }
}
