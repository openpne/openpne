<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\Member;

class UpdateDiary
{
    public function __invoke(Member $actor, Diary $diary, DiaryFormData $data): void
    {
        if (! $actor->is($diary->member)) {
            throw new DiaryActionException(DiaryActionFailure::NotAuthor);
        }

        $diary->update([
            'title' => $data->title,
            'body' => $data->body,
            'visibility' => $data->visibility,
        ]);
    }
}
