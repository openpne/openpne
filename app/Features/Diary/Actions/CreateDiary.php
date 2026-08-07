<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Events\DiaryPosted;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Diary;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Http\UploadedFile;

class CreateDiary
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, DiaryFormData $data, array $images = []): Diary
    {
        $diary = $this->images->attach(
            'diary',
            $images,
            persist: fn (): Diary => $author->diaries()->create([
                'title' => $data->title,
                'body' => $data->body,
                'visibility' => $data->visibility,
                'format' => $data->format ?? BodyFormat::Plain,
            ]),
            relation: fn (Diary $diary) => $diary->images(),
        );

        // Fires after the image-attach transaction has committed (ShouldDispatchAfterCommit), so the
        // fan-out job re-reads a durable diary.
        DiaryPosted::dispatch($diary, $author);
        SyncLinkCard::for($diary);

        return $diary;
    }
}
