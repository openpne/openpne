<?php

namespace App\Features\Timeline\Actions;

use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Database\Eloquent\Builder;

class DeleteTimelinePost
{
    /**
     * The caller gates ownership. The cascade drops the reply rows and the join rows but never the
     * File bytes, and a reply's File — one OpenPNE 4 never writes but OpenPNE 3 allowed and an
     * upgrade brings in — is reachable from nowhere else, so both are collected before the delete
     * and purged after it.
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
            $file->delete();
        }
    }
}
