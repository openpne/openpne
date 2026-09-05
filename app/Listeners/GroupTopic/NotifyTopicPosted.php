<?php

namespace App\Listeners\GroupTopic;

use App\Features\GroupTopic\Events\TopicPosted;
use App\Jobs\BroadcastTopicPosted;

/** Queued because the audience is group-wide and must not be walked in the request. */
class NotifyTopicPosted
{
    public function handle(TopicPosted $event): void
    {
        BroadcastTopicPosted::dispatch((int) $event->topic->getKey());
    }
}
