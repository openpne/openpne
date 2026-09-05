<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Events\EventCommentPosted;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\File;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateEventComment
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Lock the parent event row first so concurrent commenters serialize on a row that always
     * exists: an empty thread has no comment rows, so max(number) alone would let two posts both
     * claim 1. The same save bumps event_updated_at and updated_at, lifting the event on the board.
     *
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, GroupEvent $event, string $body, array $images = []): GroupEventComment
    {
        if (! GroupEventAccess::canComment($event, $author)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotComment);
        }

        return $this->images->compensating(function (callable $store) use ($author, $event, $body, $images): GroupEventComment {
            $locked = GroupEvent::whereKey($event->getKey())->lockForUpdate()->first();

            return $this->persist($store, $author, $locked, $body, $images);
        });
    }

    /**
     * The caller must already be inside a compensating transaction holding $event's row lock, and
     * must provide its byte-tracking $store.
     *
     * @param  callable(UploadedFile, string, int): File  $store
     * @param  array<int, UploadedFile>  $images
     */
    public function persist(callable $store, Member $author, GroupEvent $event, string $body, array $images): GroupEventComment
    {
        $number = (int) $event->comments()->max('number') + 1;

        $comment = $event->comments()->create([
            'member_id' => $author->getKey(),
            'number' => $number,
            'body' => $body,
        ]);

        $event->event_updated_at = now();
        $event->save();

        foreach (array_values($images) as $index => $upload) {
            $file = $store($upload, 'groupEventComment', (int) $comment->getKey());
            $comment->images()->create(['file_id' => $file->getKey(), 'number' => $index + 1]);
        }

        EventCommentPosted::dispatch($event, $comment, $author);
        // Held until the commit: the job re-reads the row by id (SyncLinkCard::for).
        SyncLinkCard::for($comment);

        return $comment;
    }
}
