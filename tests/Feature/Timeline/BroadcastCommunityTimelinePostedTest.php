<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use App\Features\Community\CommunityNewPostFanout;
use App\Features\Community\CommunityRole;
use App\Features\Community\Queries\CommunityNewPostRecipients;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Jobs\BroadcastCommunityTimelinePosted;
use App\Jobs\BroadcastTimelinePosted;
use App\Listeners\Timeline\NotifyTimelinePosted;
use App\Mail\Template\MailTemplateService;
use App\Models\Community;
use App\Models\CommunityMember;
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
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $post = TimelinePost::factory()->inCommunity($community)->create();

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
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $author = $this->joined($community);
        $fellow = $this->joined($community);
        $outsider = Member::factory()->create();

        $this->broadcast($this->postIn($community, $author));

        Notification::assertSentTo($fellow, TimelineCommunityPostedNotification::class);
        Notification::assertNotSentTo($outsider, TimelineCommunityPostedNotification::class);
        Notification::assertNotSentTo($author, TimelineCommunityPostedNotification::class);
    }

    public function test_the_community_kind_carries_the_opt_out(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $silenced = $this->joined($community);
        $silenced->setNotificationSetting(NotificationKind::TimelineNewPostCommunity, NotificationChannel::Web, false);
        $silenced->setNotificationSetting(NotificationKind::TimelineNewPostCommunity, NotificationChannel::Mail, false);

        // Silencing the SNS-wide kind must not silence this one, and vice versa.
        $unrelated = $this->joined($community);
        $unrelated->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Web, false);
        $unrelated->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Mail, false);

        $this->broadcast($this->postIn($community, $author));

        Notification::assertNotSentTo($silenced, TimelineCommunityPostedNotification::class);
        Notification::assertSentTo($unrelated, TimelineCommunityPostedNotification::class);
    }

    public function test_a_mentioned_member_is_not_told_twice(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $mentioned = $this->joined($community);

        $this->broadcast($this->postIn($community, $author), [$mentioned->getKey()]);

        Notification::assertNotSentTo($mentioned, TimelineCommunityPostedNotification::class);
    }

    public function test_a_member_who_left_stops_receiving_the_thread(): void
    {
        // The eligibility predicate is re-read at delivery, so a queued notification does not carry
        // a community's body to someone who has since left it.
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $author = $this->joined($community);
        $former = $this->joined($community);
        $post = $this->postIn($community, $author);

        $notification = new TimelineCommunityPostedNotification($post, $author, ['database']);
        $this->assertTrue($notification->shouldSend($former, 'database'));

        CommunityMember::where('community_id', $community->getKey())
            ->where('member_id', $former->getKey())->delete();

        $this->assertFalse($notification->shouldSend($former->fresh(), 'database'));
    }

    private function broadcast(TimelinePost $post, array $mentionedMemberIds = []): void
    {
        (new BroadcastCommunityTimelinePosted((int) $post->getKey(), $mentionedMemberIds))
            ->handle(app(CommunityNewPostFanout::class), app(CommunityNewPostRecipients::class), app(MailTemplateService::class));
    }

    /**
     * Built by factory, not through CreateTimelinePost: the action dispatches the event, whose
     * listener runs this very job on the sync queue, and a second delivery from the run under test
     * would hide whichever exclusion it was meant to prove.
     */
    private function postIn(Community $community, Member $author): TimelinePost
    {
        return TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);
    }

    private function joined(Community $community): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => CommunityRole::Member,
        ]);

        return $member;
    }
}
