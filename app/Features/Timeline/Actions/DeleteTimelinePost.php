<?php

namespace App\Features\Timeline\Actions;

use App\Models\TimelinePost;

class DeleteTimelinePost
{
    /**
     * Delete a post (the controller gates author ownership). Collect the owned image File before
     * the row is gone: the FK cascade drops the *_images join row and any reply rows, but never the
     * File bytes, which a disk backend deletes irreversibly. Purge it after the post is deleted.
     */
    public function __invoke(TimelinePost $post): void
    {
        $files = $post->images()->with('file')->get()->pluck('file')->filter()->all();

        $post->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
