<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Models\Community;
use App\Models\File;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeleteCommunity
{
    public function __construct(
        private readonly DeleteTopic $deleteTopic,
        private readonly DeleteEvent $deleteEvent,
    ) {}

    public function __invoke(Member $actor, Community $community): void
    {
        if (! CommunityMembership::isAdmin($community, $actor)) {
            throw new CommunityActionException(CommunityActionFailure::NotAdmin);
        }

        $this->purge($community);
    }

    /**
     * Delete the community and purge every owned image File's bytes — no authorization. The admin
     * moderation panel calls this directly (the panel's `admin` guard is an AdminUser, not a Member);
     * frontend callers always go through __invoke.
     */
    public function purge(Community $community): void
    {
        // The community cascade drops nested topics/events/comments and their *_image link rows, but
        // never the File bytes. Delete each topic/event through its own purge first (each collects and
        // purges its and its comments' image bytes), so nothing orphans; then the community itself.
        foreach ($community->topics()->get() as $topic) {
            $this->deleteTopic->purge($topic);
        }

        foreach ($community->events()->get() as $event) {
            $this->deleteEvent->purge($event);
        }

        // The cascade removes memberships and join requests but never the top-image File bytes. Read
        // the image under the same lock as the delete so a concurrent edit that just replaced it can't
        // leave the new File orphaned (file_id is a mutable self-column — a stale read would miss that
        // edit's image). Purge the bytes after commit.
        $image = DB::transaction(function () use ($community): ?File {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return null; // already deleted by a concurrent request
            }

            $file = $locked->image()->first();
            $locked->delete();

            return $file;
        });

        $image?->delete(); // deleting the File purges its bytes
    }
}
