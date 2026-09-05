<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMessage;
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

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(Group $group): void
    {
        // Each nested topic and event goes through its own purge first: the group cascade drops
        // their rows but never their File bytes.
        foreach ($group->topics()->get() as $topic) {
            $this->deleteTopic->purge($topic);
        }

        foreach ($group->events()->get() as $event) {
            $this->deleteEvent->purge($event);
        }

        // The talk sweep and the group's own image are read under this lock — talk is written
        // concurrently, and file_id is a mutable self-column — and the bytes are purged after the
        // commit (docs/internals/group-boards.md, "Tearing a group down").
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

            // `reactions.reactable_id` is polymorphic and carries no foreign key, so the cascade
            // would leave every reaction behind (docs/internals/group-talk.md, "Reclaiming the rows").
            DB::table('reactions')
                ->where('reactable_type', (new GroupMessage)->getMorphClass())
                ->whereIn('reactable_id', DB::table('group_messages')->where('group_id', $locked->getKey())->select('id'))
                ->delete();

            $file = $locked->image()->first();
            $locked->delete();

            return [$file, $talkImages];
        });

        $image?->delete();
        foreach ($talkImages as $talkImage) {
            $talkImage->delete();
        }
    }
}
