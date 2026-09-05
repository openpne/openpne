<?php

namespace App\Listeners\Diary;

use App\Features\Diary\Events\DiaryPosted;
use App\Jobs\BroadcastDiaryPosted;

/** Queued because the audience can be member-wide and must not be walked in the request. */
class NotifyDiaryPosted
{
    public function handle(DiaryPosted $event): void
    {
        BroadcastDiaryPosted::dispatch((int) $event->diary->getKey());
    }
}
