<?php

declare(strict_types=1);

namespace Tests\Feature\Group\Listeners;

use App\Features\Group\GroupRole;
use App\Features\GroupEvent\Events\EventPosted;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Features\GroupTopic\Events\TopicPosted;
use App\Jobs\BroadcastEventPosted;
use App\Jobs\BroadcastTopicPosted;
use App\Listeners\GroupEvent\NotifyEventPosted;
use App\Listeners\GroupTopic\NotifyTopicPosted;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotifyGroupNewPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_topic_listener_dispatches_the_fan_out_job(): void
    {
        Bus::fake([BroadcastTopicPosted::class]);
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyTopicPosted::class)->handle(new TopicPosted($topic, $author));

        Bus::assertDispatched(BroadcastTopicPosted::class, fn (BroadcastTopicPosted $job) => $job->topicId === (int) $topic->getKey());
    }

    public function test_the_event_listener_dispatches_the_fan_out_job(): void
    {
        Bus::fake([BroadcastEventPosted::class]);
        $author = Member::factory()->create();
        $event = GroupEvent::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyEventPosted::class)->handle(new EventPosted($event, $author));

        Bus::assertDispatched(BroadcastEventPosted::class, fn (BroadcastEventPosted $job) => $job->eventId === (int) $event->getKey());
    }

    public function test_creating_a_topic_dispatches_the_event(): void
    {
        Event::fake([TopicPosted::class]);
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $group->members()->create(['member_id' => $author->getKey(), 'role' => GroupRole::Member]);

        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Title', 'Body'));

        Event::assertDispatched(
            TopicPosted::class,
            fn (TopicPosted $event) => $event->topic->is($topic) && $event->author->is($author),
        );
    }
}
