<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Data\DiaryFormData;
use App\Files\PostImages;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateDiary
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, DiaryFormData $data, array $images = []): Diary
    {
        return $this->images->attach(
            'diary',
            $images,
            persist: fn (): Diary => $author->diaries()->create([
                'title' => $data->title,
                'body' => $data->body,
                'visibility' => $data->visibility,
            ]),
            relation: fn (Diary $diary) => $diary->images(),
        );
    }
}
