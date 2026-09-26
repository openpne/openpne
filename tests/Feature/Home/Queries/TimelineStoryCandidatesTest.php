<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Queries\StoryCandidates;
use App\Features\Home\Queries\TimelineStoryCandidates;
use App\Models\TimelinePost;
use Carbon\CarbonImmutable;

class TimelineStoryCandidatesTest extends StoryCandidatesTestCase
{
    protected function candidates(): StoryCandidates
    {
        return app(TimelineStoryCandidates::class);
    }

    protected function readable(CarbonImmutable $at, int $engagement = 0): TimelinePost
    {
        $post = $this->at($at, fn (): TimelinePost => TimelinePost::factory()->create());
        TimelinePost::factory()->count($engagement)->replyTo($post)->create();

        return $post;
    }

    protected function walledOff(CarbonImmutable $at): TimelinePost
    {
        return $this->at($at, fn (): TimelinePost => TimelinePost::factory()->friends()->create());
    }

    public function test_a_reply_is_part_of_a_story_and_never_one_itself(): void
    {
        $window = $this->window();
        $post = $this->at($window->end->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());
        $reply = $this->at($window->end, fn (): TimelinePost => TimelinePost::factory()->replyTo($post)->create());

        $this->assertSame([$post->getKey()], $this->ids($this->candidates()($window, 10)));
        $this->assertNull($this->candidates()->find($reply->getKey()));
    }
}
