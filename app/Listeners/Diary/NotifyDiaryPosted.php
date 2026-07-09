<?php

namespace App\Listeners\Diary;

use App\Features\Diary\Events\DiaryPosted;
use App\Jobs\BroadcastDiaryPosted;

/**
 * Hands the new-diary fan-out to a queued job: the audience can be member-wide, so it must not run in
 * the request. Only the diary id crosses to the job, which re-reads (and no-ops if it is already gone).
 */
class NotifyDiaryPosted
{
    public function handle(DiaryPosted $event): void
    {
        BroadcastDiaryPosted::dispatch((int) $event->diary->getKey());
    }
}
