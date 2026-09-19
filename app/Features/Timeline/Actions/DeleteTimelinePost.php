<?php

namespace App\Features\Timeline\Actions;

use App\Features\Timeline\TimelineThreadLock;
use App\Models\Reaction;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Support\Facades\DB;

class DeleteTimelinePost
{
    /**
     * The caller gates ownership. The cascade drops the reply rows and the join rows but never the
     * File bytes nor the reactions (`reactable_id` carries no foreign key), so both are collected
     * under the thread lock; the reactions go inside the transaction and the Files after it.
     */
    public function __invoke(TimelinePost $post): void
    {
        $files = DB::transaction(function () use ($post): array {
            if (! TimelineThreadLock::hold($post)) {
                return [];
            }

            $ids = [(int) $post->getKey(), ...$post->replies()->pluck('id')->all()];

            Reaction::query()
                ->where('reactable_type', $post->getMorphClass())
                ->whereIn('reactable_id', $ids)
                ->delete();

            $files = TimelinePostImage::query()
                ->whereIn('timeline_post_id', $ids)
                ->with('file')
                ->get()
                ->pluck('file')
                ->filter()
                ->all();

            $post->delete();

            return $files;
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
