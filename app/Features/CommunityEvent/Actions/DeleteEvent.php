<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\CommunityEvent;
use App\Models\File;
use App\Models\Member;

class DeleteEvent
{
    public function __invoke(Member $actor, CommunityEvent $event): void
    {
        if (! CommunityEventAccess::canEditEvent($event, $actor)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotEdit);
        }

        // Collect every owned image File (the event's and its comments') before the row is gone: the
        // FK cascade drops the *_image link rows but never the File bytes, which a disk backend
        // deletes irreversibly. Purge them after the event is deleted (post-commit).
        $files = $this->ownedImageFiles($event);

        $event->delete(); // FK cascade removes comments, participant rows and all *_image link rows

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }

    /** @return array<int, File> */
    private function ownedImageFiles(CommunityEvent $event): array
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
