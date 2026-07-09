<?php

namespace App\Listeners\CommunityTopic;

use App\Features\CommunityTopic\Events\TopicPosted;
use App\Jobs\BroadcastTopicPosted;

/** Hands the new-topic fan-out to a queued job (the audience is community-wide). */
class NotifyTopicPosted
{
    public function handle(TopicPosted $event): void
    {
        BroadcastTopicPosted::dispatch((int) $event->topic->getKey());
    }
}
