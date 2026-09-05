<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Models\File;
use App\Models\GroupEvent;
use App\Models\Member;

class DeleteEvent
{
    public function __invoke(Member $actor, GroupEvent $event): void
    {
        if (! GroupEventAccess::canEditEvent($event, $actor)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotEdit);
        }

        $this->purge($event);
    }

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(GroupEvent $event): void
    {
        // Collect every owned image File — the event's and its comments' — before the row is gone:
        // the cascade drops the *_image link rows but never the bytes, which a disk deletes for good.
        $files = $this->ownedImageFiles($event);

        $event->delete(); // FK cascade removes comments, participant rows and all *_image link rows

        foreach ($files as $file) {
            $file->delete();
        }
    }

    /** @return array<int, File> */
    private function ownedImageFiles(GroupEvent $event): array
    {
        $files = $event->images()->with('file')->get()->pluck('file')->all();

        foreach ($event->comments()->with('images.file')->get() as $comment) {
            foreach ($comment->images as $image) {
                $files[] = $image->file;
            }
        }

        return array_values(array_filter($files));
    }
}
