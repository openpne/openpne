<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Files\PostImages;
use App\Models\GroupEvent;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

/**
 * One outermost PostImages::compensating() callback wraps the toggle and the comment, so a roster
 * guard, a failed image write or a commit failure rolls back both and purges the bytes already
 * written. A separate outer transaction would not: compensating() undoes only its own byte writes.
 */
class SubmitEventComment
{
    public function __construct(
        private readonly PostImages $images,
        private readonly ToggleParticipation $toggle,
        private readonly CreateEventComment $comment,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $images
     * @return bool|null the new participation state when $toggleRoster, else null (comment only)
     */
    public function __invoke(Member $member, GroupEvent $event, string $body, array $images, bool $toggleRoster): ?bool
    {
        // Commenting and RSVP share the same membership gate.
        if (! GroupEventAccess::canComment($event, $member)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotComment);
        }

        return $this->images->compensating(function (callable $store) use ($member, $event, $body, $images, $toggleRoster): ?bool {
            $locked = GroupEvent::whereKey($event->getKey())->lockForUpdate()->first();

            // Toggle before the comment persists, like OpenPNE 3: a guard failure (closed/expired/
            // full) throws here, before any image byte is written, and aborts the whole submission.
            $joined = $toggleRoster ? $this->toggle->apply($member, $locked) : null;
            $this->comment->persist($store, $member, $locked, $body, $images);

            return $joined;
        });
    }
}
