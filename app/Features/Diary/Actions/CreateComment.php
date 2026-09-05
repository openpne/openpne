<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Events\DiaryCommentPosted;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateComment
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * The caller gates viewability; this only appends. The parent diary row is locked because an
     * empty thread has no comment row to serialize on (docs/internals/diary.md, "Comment numbering").
     *
     * @param  array<int, UploadedFile>  $images  attached images, at most the upload cap
     */
    public function __invoke(Member $author, Diary $diary, string $body, array $images = []): DiaryComment
    {
        return $this->images->compensating(function (callable $store) use ($author, $diary, $body, $images): DiaryComment {
            Diary::whereKey($diary->getKey())->lockForUpdate()->first();

            $number = (int) $diary->comments()->max('number') + 1;

            $comment = $diary->comments()->create([
                'member_id' => $author->getKey(),
                'number' => $number,
                'body' => $body,
            ]);

            foreach (array_values($images) as $upload) {
                $file = $store($upload, 'diaryComment', (int) $comment->getKey());
                $comment->images()->create(['file_id' => $file->getKey()]);
            }

            DiaryCommentPosted::dispatch($diary, $comment, $author);
            // Held until the commit: the job re-reads the row by id (SyncLinkCard::for).
            SyncLinkCard::for($comment);

            return $comment;
        });
    }
}
