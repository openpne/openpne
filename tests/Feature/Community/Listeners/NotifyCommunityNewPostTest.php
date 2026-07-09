<?php

declare(strict_types=1);

namespace Tests\Feature\Community\Listeners;

use App\Features\Community\CommunityRole;
use App\Features\CommunityEvent\Events\EventPosted;
use App\Features\CommunityTopic\Actions\CreateTopic;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Events\TopicPosted;
use App\Jobs\BroadcastEventPosted;
use App\Jobs\BroadcastTopicPosted;
use App\Listeners\CommunityEvent\NotifyEventPosted;
use App\Listeners\CommunityTopic\NotifyTopicPosted;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotifyCommunityNewPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_topic_listener_dispatches_the_fan_out_job(): void
    {
        Bus::fake([BroadcastTopicPosted::class]);
        $author = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyTopicPosted::class)->handle(new TopicPosted($topic, $author));

        Bus::assertDispatched(BroadcastTopicPosted::class, fn (BroadcastTopicPosted $job) => $job->topicId === (int) $topic->getKey());
    }

    public function test_the_event_listener_dispatches_the_fan_out_job(): void
    {
        Bus::fake([BroadcastEventPosted::class]);
        $author = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyEventPosted::class)->handle(new EventPosted($event, $author));

        Bus::assertDispatched(BroadcastEventPosted::class, fn (BroadcastEventPosted $job) => $job->eventId === (int) $event->getKey());
    }

    public function test_creating_a_topic_dispatches_the_event(): void
    {
        Event::fake([TopicPosted::class]);
        $community = Community::factory()->create();
        $author = Member::factory()->create();
        $community->members()->create(['member_id' => $author->getKey(), 'role' => CommunityRole::Member]);

        $topic = app(CreateTopic::class)($author, $community, new CommunityTopicFormData('Title', 'Body'));

        Event::assertDispatched(
            TopicPosted::class,
            fn (TopicPosted $event) => $event->topic->is($topic) && $event->author->is($author),
        );
    }
}
