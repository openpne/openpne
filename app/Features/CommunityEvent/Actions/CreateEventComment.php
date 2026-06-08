<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Files\PostImages;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\File;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateEventComment
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Append a comment to an event the author may comment on. `number` is the per-event sequence;
     * lock the parent event row first so concurrent commenters serialize on a row that always exists
     * (an empty thread has no comment rows to lock, so max(number) alone would let two posts both
     * claim 1). The same row update bumps both event_updated_at (OpenPNE 3 comment preSave) and
     * updated_at (form save), lifting the event to the top of the board.
     *
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, CommunityEvent $event, string $body, array $images = []): CommunityEventComment
    {
        if (! CommunityEventAccess::canComment($event, $author)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotComment);
        }

        return $this->images->compensating(function (callable $store) use ($author, $event, $body, $images): CommunityEventComment {
            $locked = CommunityEvent::whereKey($event->getKey())->lockForUpdate()->first();

            return $this->persist($store, $author, $locked, $body, $images);
        });
    }

    /**
     * Persist the comment and its images, assuming the caller is inside a compensating transaction
     * that already holds $event's row lock and provides the byte-tracking $store. Split out so the
     * merged comment flow (SubmitEventComment) can run it in the same compensating transaction as the
     * roster toggle — one outermost compensating wrapper, so a rollback purges the image bytes too.
     *
     * @param  callable(UploadedFile, string, int): File  $store
     * @param  array<int, UploadedFile>  $images
     */
    public function persist(callable $store, Member $author, CommunityEvent $event, string $body, array $images): CommunityEventComment
    {
        $number = (int) $event->comments()->max('number') + 1;

        $comment = $event->comments()->create([
            'member_id' => $author->getKey(),
            'number' => $number,
            'body' => $body,
        ]);

        $event->event_updated_at = now();
        $event->save(); // dirty → updated_at bumped too, lifting the event on the board

        foreach (array_values($images) as $index => $upload) {
            $file = $store($upload, 'communityEventComment', (int) $comment->getKey());
            $comment->images()->create(['file_id' => $file->getKey(), 'number' => $index + 1]);
        }

        return $comment;
    }
}
