<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Queries\DiaryStoryCandidates;
use App\Features\Home\Queries\StoryCandidates;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Support\Visibility;
use Carbon\CarbonImmutable;

class DiaryStoryCandidatesTest extends StoryCandidatesTestCase
{
    protected function candidates(): StoryCandidates
    {
        return app(DiaryStoryCandidates::class);
    }

    protected function readable(CarbonImmutable $at, int $engagement = 0): Diary
    {
        $diary = $this->at($at, fn (): Diary => Diary::factory()->create(['visibility' => Visibility::Members]));
        for ($number = 1; $number <= $engagement; $number++) {
            DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'number' => $number]);
        }

        return $diary;
    }

    protected function walledOff(CarbonImmutable $at): Diary
    {
        return $this->at($at, fn (): Diary => Diary::factory()->friends()->create());
    }

    public function test_an_open_diary_is_a_story_like_a_members_one(): void
    {
        $window = $this->window();
        $open = $this->at($window->end, fn (): Diary => Diary::factory()->create(['visibility' => Visibility::Open]));

        $this->assertSame([$open->getKey()], $this->ids($this->candidates()($window, 10)));
    }
}
