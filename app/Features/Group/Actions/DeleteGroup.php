<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Models\File;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeleteGroup
{
    public function __construct(
        private readonly DeleteTopic $deleteTopic,
        private readonly DeleteEvent $deleteEvent,
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

        // The cascade removes memberships and join requests but never the top-image File bytes. Read
        // the image under the same lock as the delete so a concurrent edit that just replaced it can't
        // leave the new File orphaned (file_id is a mutable self-column — a stale read would miss that
        // edit's image). Purge the bytes after commit.
        //
        // The talk's image Files are collected under that same lock, unlike the topic/event
        // sweeps above: talk is written concurrently by design, and a message committed after any
        // earlier enumeration would slip past it — its join row cascading away while the File row and
        // bytes stay. The parent-row X-lock closes that window (a new message's FK check takes a
        // shared lock on the group row and waits), the cascade drops the message and join rows, and
        // the Files are purged once the delete has committed.
        [$image, $talkImages] = DB::transaction(function () use ($group): array {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return [null, new Collection]; // already deleted by a concurrent request
            }

            $talkImages = File::query()
                ->whereIn('id', DB::table('group_message_images')
                    ->join('group_messages', 'group_messages.id', '=', 'group_message_images.group_message_id')
                    ->where('group_messages.group_id', $locked->getKey())
                    ->select('group_message_images.file_id'))
                ->get();

            $file = $locked->image()->first();
            $locked->delete();

            return [$file, $talkImages];
        });

        $image?->delete(); // deleting a File purges its bytes
        foreach ($talkImages as $talkImage) {
            $talkImage->delete();
        }
    }
}
