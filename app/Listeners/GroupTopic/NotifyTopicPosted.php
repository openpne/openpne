<?php

namespace App\Listeners\GroupTopic;

use App\Features\GroupTopic\Events\TopicPosted;
use App\Jobs\BroadcastTopicPosted;

/** Hands the new-topic fan-out to a queued job (the audience is group-wide). */
class NotifyTopicPosted
{
    public function handle(TopicPosted $event): void
    {
        BroadcastTopicPosted::dispatch((int) $event->topic->getKey());
    }
}
