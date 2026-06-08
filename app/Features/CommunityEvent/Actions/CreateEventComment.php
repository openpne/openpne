<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Files\PostImages;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
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

        return $this->images->attach(
            'communityEventComment',
            $images,
            persist: function () use ($author, $event, $body): CommunityEventComment {
                CommunityEvent::whereKey($event->getKey())->lockForUpdate()->first();

                $number = (int) $event->comments()->max('number') + 1;

                $comment = $event->comments()->create([
                    'member_id' => $author->getKey(),
                    'number' => $number,
                    'body' => $body,
                ]);

                $event->event_updated_at = now();
                $event->save(); // dirty → updated_at bumped too, lifting the event on the board

                return $comment;
            },
            relation: fn (CommunityEventComment $comment) => $comment->images(),
        );
    }
}
