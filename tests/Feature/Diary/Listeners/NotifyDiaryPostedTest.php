<?php

declare(strict_types=1);

namespace Tests\Feature\Diary\Listeners;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Events\DiaryPosted;
use App\Jobs\BroadcastDiaryPosted;
use App\Listeners\Diary\NotifyDiaryPosted;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotifyDiaryPostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_listener_dispatches_the_fan_out_job(): void
    {
        Bus::fake([BroadcastDiaryPosted::class]);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyDiaryPosted::class)->handle(new DiaryPosted($diary, $author));

        Bus::assertDispatched(
            BroadcastDiaryPosted::class,
            fn (BroadcastDiaryPosted $job) => $job->diaryId === (int) $diary->getKey(),
        );
    }

    public function test_creating_a_diary_dispatches_the_event(): void
    {
        Event::fake([DiaryPosted::class]);
        $author = Member::factory()->create();

        $diary = app(CreateDiary::class)($author, new DiaryFormData('Title', 'Body', Visibility::Members));

        Event::assertDispatched(
            DiaryPosted::class,
            fn (DiaryPosted $event) => $event->diary->is($diary) && $event->author->is($author),
        );
    }
}
