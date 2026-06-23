<?php

namespace App\Features\Diary\Actions;

use App\Files\PostImages;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateComment
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Append a comment to a diary the author may already view (the controller gates
     * viewability). `number` is the per-diary sequence. Lock the parent diary row first so
     * concurrent commenters serialize on a row that always exists: an empty thread has no
     * comment rows to lock, so `max(number)` alone would let two posts both claim 1. This is
     * the only guard — OpenPNE 3's index is non-unique and legacy data may already carry
     * duplicate numbers, so a unique constraint would reject them on upgrade.
     *
     * Image bytes are rollback-safe: a disk write that fails mid-store is compensated when the
     * transaction rolls back. Comment images carry no slot number (OpenPNE 3 has none).
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

            return $comment;
        });
    }
}
