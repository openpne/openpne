<?php

namespace App\Features\Timeline\Actions;

use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Database\Eloquent\Builder;

class DeleteTimelinePost
{
    /**
     * Delete a post (the controller gates author ownership). Collect the owned image Files before
     * the rows are gone: the FK cascade drops the *_images join rows and any reply rows, but never
     * the File bytes, which a disk backend deletes irreversibly. Purge them after the delete.
     *
     * The replies are collected too. OpenPNE 4's own writer attaches no image to a reply, but the
     * column allows one and OpenPNE 3's API did not refuse the combination, so upgraded threads can
     * carry one — and a reply's File is reachable from nowhere else once the cascade has run.
     * A thread is two levels deep (replies re-center to the root), so direct replies are all of it.
     */
    public function __invoke(TimelinePost $post): void
    {
        $files = TimelinePostImage::query()
            ->where(fn (Builder $thread) => $thread
                ->where('timeline_post_id', $post->getKey())
                ->orWhereIn('timeline_post_id', $post->replies()->select('id')))
            ->with('file')
            ->get()
            ->pluck('file')
            ->filter()
            ->all();

        $post->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
