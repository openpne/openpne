<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupTalk\Actions\DeleteGroupMessage;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Models\File;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeleteGroup
{
    public function __construct(
        private readonly DeleteTopic $deleteTopic,
        private readonly DeleteEvent $deleteEvent,
        private readonly DeleteTimelinePost $deleteTimelinePost,
        private readonly DeleteGroupMessage $deleteGroupMessage,
    ) {}

    public function __invoke(Member $actor, Group $group): void
    {
        if (! GroupMembership::isAdmin($group, $actor)) {
            throw new GroupActionException(GroupActionFailure::NotAdmin);
        }

        $this->purge($group);
    }

    /**
     * Delete the group and purge every owned image File's bytes — no authorization. The admin
     * moderation panel calls this directly (the panel's `admin` guard is an AdminUser, not a Member);
     * frontend callers always go through __invoke.
     */
    public function purge(Group $group): void
    {
        // The group cascade drops nested topics/events/comments and their *_image link rows, but
        // never the File bytes. Delete each topic/event through its own purge first (each collects and
        // purges its and its comments' image bytes), so nothing orphans; then the group itself.
        foreach ($group->topics()->get() as $topic) {
            $this->deleteTopic->purge($topic);
        }

        foreach ($group->events()->get() as $event) {
            $this->deleteEvent->purge($event);
        }

        // Same for the group's timeline: the cascade drops timeline_post_images rows but not the
        // File bytes. Top-level posts only — DeleteTimelinePost cascades their replies, which carry
        // no image of their own.
        foreach ($group->timelinePosts()->whereNull('in_reply_to_id')->get() as $post) {
            ($this->deleteTimelinePost)($post);
        }

        // And the group's talk: the cascade drops group_message_images rows but not the File bytes.
        // Every message, not only some — talk is flat, so there is no parent whose purge would reach
        // the rest.
        foreach ($group->messages()->get() as $message) {
            $this->deleteGroupMessage->purge($message);
        }

        // The cascade removes memberships and join requests but never the top-image File bytes. Read
        // the image under the same lock as the delete so a concurrent edit that just replaced it can't
        // leave the new File orphaned (file_id is a mutable self-column — a stale read would miss that
        // edit's image). Purge the bytes after commit.
        $image = DB::transaction(function () use ($group): ?File {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->first();
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
