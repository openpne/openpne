<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Queries\StoryCandidates;
use App\Features\Home\Queries\TopicStoryCandidates;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Carbon\CarbonImmutable;

class TopicStoryCandidatesTest extends StoryCandidatesTestCase
{
    protected function candidates(): StoryCandidates
    {
        return app(TopicStoryCandidates::class);
    }

    protected function readable(CarbonImmutable $at, int $engagement = 0): GroupTopic
    {
        $topic = $this->at($at, fn (): GroupTopic => GroupTopic::factory()->create());
        for ($number = 1; $number <= $engagement; $number++) {
            GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'number' => $number]);
        }

        return $topic;
    }

    protected function walledOff(CarbonImmutable $at): GroupTopic
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);

        return $this->at($at, fn (): GroupTopic => GroupTopic::factory()->create(['group_id' => $group->getKey()]));
    }
}
