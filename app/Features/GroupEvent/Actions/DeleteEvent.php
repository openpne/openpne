<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Models\File;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class DeleteEvent
{
    public function __invoke(Member $actor, GroupEvent $event): void
    {
        if (! GroupEventAccess::canEditEvent($event, $actor)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotEdit);
        }

        $this->purge($event);
    }

    /**
     * No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities").
     * The cascade drops the comments and the `*_image` link rows but never the File bytes nor the
     * comments' reactions, so both are collected under the event lock; the reactions go inside the
     * transaction and the Files after it.
     */
    public function purge(GroupEvent $event): void
    {
        $files = DB::transaction(function () use ($event): array {
            $locked = GroupEvent::whereKey($event->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return [];
            }

            $commentIds = $locked->comments()->sharedLock()->pluck('id')->all();

            Reaction::query()
                ->where('reactable_type', (new GroupEventComment)->getMorphClass())
                ->whereIn('reactable_id', $commentIds)
                ->delete();

            $files = File::query()
                ->whereIn('id', DB::table('group_event_images')->where('post_id', $locked->getKey())->select('file_id'))
                ->orWhereIn('id', DB::table('group_event_comment_images')->whereIn('post_id', $commentIds)->select('file_id'))
                ->get()
                ->all();

            $locked->delete();

            return $files;
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
