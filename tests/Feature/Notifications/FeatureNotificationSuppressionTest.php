<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Friend\Events\FriendRequested;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\Member;
use App\Notifications\CommunityTopic\TopicPostedNotification;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A switched-off unit sends nothing — and the case that decides where the gate may live is the
 * queued one: a notification queued while the unit was on carries the channels via() picked back
 * then, and SendQueuedNotifications replays exactly those. Only shouldSend() runs late enough.
 */
class FeatureNotificationSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Self-contained mailer: sentMailCount() reads the array transport's buffer, and the
        // environment's MAIL_MAILER (smtp under docker) must not decide whether that exists.
        // Forget any resolved mailer so the override takes effect.
        config(['mail.default' => 'array']);
        Mail::forgetMailers();
    }

    /**
     * Queue a diary broadcast the way the fan-out does, and hand back the jobs waiting to run.
     *
     * @return Collection<int, SendQueuedNotifications>
     */
    private function queuedDiaryBroadcast(): Collection
    {
        Queue::fake();

        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);
        Member::factory()->create()->notify(new DiaryPostedNotification($diary, $author, ['mail', 'database']));

        $jobs = Queue::pushed(SendQueuedNotifications::class);
        // One job per channel, each carrying its own — this is what leaves via() unreachable later.
        $this->assertSame([['mail'], ['database']], $jobs->pluck('channels')->all());

        return $jobs;
    }

    /** @param Collection<int, SendQueuedNotifications> $jobs */
    private function runQueued(Collection $jobs): void
    {
        $manager = $this->app->make(ChannelManager::class);
        foreach ($jobs as $job) {
            $job->handle($manager);
        }
    }

    /** Mails that reached the transport (MAIL_MAILER=array), which Mail::fake() would not see. */
    private function sentMailCount(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    private function switchOff(Feature $feature): void
    {
        $this->setSnsSetting($feature->settingKey(), false);
        $this->freshRequestState();
    }

    public function test_a_queued_notification_delivers_both_channels_while_the_unit_is_on(): void
    {
        $this->runQueued($this->queuedDiaryBroadcast());

        $this->assertSame(1, $this->sentMailCount());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_notification_queued_before_the_unit_went_off_sends_neither_mail_nor_row(): void
    {
        $jobs = $this->queuedDiaryBroadcast();

        $this->switchOff(Feature::Diary);
        $this->runQueued($jobs);

        $this->assertSame(0, $this->sentMailCount());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_an_event_fired_while_the_unit_is_off_records_nothing(): void
    {
        [$requester, $target] = Member::factory()->count(2)->create()->all();

        $this->switchOff(Feature::Friend);
        FriendRequested::dispatch($requester, $target);

        $this->assertSame(0, $this->sentMailCount());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_same_event_records_a_row_while_the_unit_is_on(): void
    {
        [$requester, $target] = Member::factory()->count(2)->create()->all();

        FriendRequested::dispatch($requester, $target);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_an_ancestor_switched_off_suppresses_a_child_units_notification(): void
    {
        $author = Member::factory()->create();
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $author->getKey(),
        ]);

        // communityTopic's own flag stays on; Feature::enabled() walks up to `community`.
        $this->switchOff(Feature::Community);

        Member::factory()->create()->notify(new TopicPostedNotification($community, $topic, $author, ['database']));

        $this->assertDatabaseCount('notifications', 0);
    }
}
