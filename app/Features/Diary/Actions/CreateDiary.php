<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Data\DiaryFormData;
use App\Models\Diary;
use App\Models\Member;

class CreateDiary
{
    public function __invoke(Member $author, DiaryFormData $data): Diary
    {
        return $author->diaries()->create([
            'title' => $data->title,
            'body' => $data->body,
            'visibility' => $data->visibility,
        ]);
    }
}
