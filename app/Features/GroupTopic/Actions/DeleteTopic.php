<?php

namespace App\Features\GroupTopic\Actions;

use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\File;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

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
     * No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities").
     * The cascade drops the comments and the `*_image` link rows but never the File bytes nor the
     * comments' reactions, so both are collected under the topic lock; the reactions go inside the
     * transaction and the Files after it.
     */
    public function purge(GroupTopic $topic): void
    {
        $files = DB::transaction(function () use ($topic): array {
            $locked = GroupTopic::whereKey($topic->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return [];
            }

            $commentIds = $locked->comments()->sharedLock()->pluck('id')->all();

            Reaction::query()
                ->where('reactable_type', (new GroupTopicComment)->getMorphClass())
                ->whereIn('reactable_id', $commentIds)
                ->delete();

            $files = File::query()
                ->whereIn('id', DB::table('group_topic_images')->where('post_id', $locked->getKey())->select('file_id'))
                ->orWhereIn('id', DB::table('group_topic_comment_images')->whereIn('post_id', $commentIds)->select('file_id'))
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
