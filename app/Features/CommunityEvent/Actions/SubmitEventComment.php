<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Files\PostImages;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

/**
 * The OpenPNE 3 merged comment endpoint: optionally toggle the roster, then post the (required)
 * comment with its images. Everything runs inside one outermost PostImages::compensating() callback,
 * so a roster guard, a failed image write, or even a commit failure rolls back the join, the comment
 * and the image rows together AND purges the already-written image bytes. Composing self-transacting
 * actions under a separate outer transaction would not — compensating() only undoes byte writes made
 * inside its own transaction, so a later outer failure would orphan them.
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
    public function __invoke(Member $member, CommunityEvent $event, string $body, array $images, bool $toggleRoster): ?bool
    {
        // Commenting and RSVP share the same membership gate (OpenPNE 3 isCreatableCommunityEventComment).
        if (! CommunityEventAccess::canComment($event, $member)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotComment);
        }

        return $this->images->compensating(function (callable $store) use ($member, $event, $body, $images, $toggleRoster): ?bool {
            $locked = CommunityEvent::whereKey($event->getKey())->lockForUpdate()->first();

            // Toggle before the comment persists, like OpenPNE 3: a guard failure (closed/expired/
            // full) throws here, before any image byte is written, and aborts the whole submission.
            $joined = $toggleRoster ? $this->toggle->apply($member, $locked) : null;
            $this->comment->persist($store, $member, $locked, $body, $images);

            return $joined;
        });
    }
}
