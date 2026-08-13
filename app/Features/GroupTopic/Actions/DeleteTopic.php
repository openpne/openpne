<?php

namespace App\Features\GroupTopic\Actions;

use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\File;
use App\Models\GroupTopic;
use App\Models\Member;

class DeleteTopic
{
    public function __invoke(Member $actor, GroupTopic $topic): void
    {
        if (! GroupTopicAccess::canEditTopic($topic, $actor)) {
            throw new GroupTopicActionException(GroupTopicActionFailure::CannotEdit);
        }

        $this->purge($topic);
    }

    /**
     * Delete the topic and purge its (and its comments') image bytes — no authorization. The admin
     * moderation panel calls this directly (the panel's `admin` guard is an AdminUser, not a Member);
     * frontend callers always go through __invoke.
     */
    public function purge(GroupTopic $topic): void
    {
        // Collect every owned image File (the topic's and its comments') before the row is gone:
        // the FK cascade drops the *_image link rows but never the File bytes, which a disk backend
        // deletes irreversibly. Purge them after the topic is deleted (post-commit).
        $files = $this->ownedImageFiles($topic);

        $topic->delete(); // FK cascade removes comments and all *_image link rows

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }

    /** @return array<int, File> */
    private function ownedImageFiles(GroupTopic $topic): array
    {
        $files = $topic->images()->with('file')->get()->pluck('file')->all();

        foreach ($topic->comments()->with('images.file')->get() as $comment) {
            foreach ($comment->images as $image) {
                $files[] = $image->file;
            }
        }

        return array_values(array_filter($files));
    }
}
