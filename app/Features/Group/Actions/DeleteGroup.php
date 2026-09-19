<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class DeleteGroup
{
    public function __invoke(Member $actor, Group $group): void
    {
        if (! GroupMembership::isAdmin($group, $actor)) {
            throw new GroupActionException(GroupActionFailure::NotAdmin);
        }

        $this->purge($group);
    }

    /**
     * No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities").
     * Every File and reaction under the group is collected under the group row's lock and, for the
     * boards, under each topic's and event's, and the bytes are purged after the commit
     * (docs/internals/group-boards.md, "Tearing a group down").
     */
    public function purge(Group $group): void
    {
        $files = DB::transaction(function () use ($group): array {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return []; // already deleted by a concurrent request
            }

            $topicIds = GroupTopic::query()->where('group_id', $locked->getKey())->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $eventIds = GroupEvent::query()->where('group_id', $locked->getKey())->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $topicCommentIds = GroupTopicComment::query()->whereIn('group_topic_id', $topicIds)->sharedLock()->pluck('id')->all();
            $eventCommentIds = GroupEventComment::query()->whereIn('group_event_id', $eventIds)->sharedLock()->pluck('id')->all();

            $files = File::query()
                ->whereIn('id', DB::table('group_message_images')
                    ->join('group_messages', 'group_messages.id', '=', 'group_message_images.group_message_id')
                    ->where('group_messages.group_id', $locked->getKey())
                    ->select('group_message_images.file_id'))
                ->orWhereIn('id', DB::table('group_topic_images')->whereIn('post_id', $topicIds)->select('file_id'))
                ->orWhereIn('id', DB::table('group_topic_comment_images')->whereIn('post_id', $topicCommentIds)->select('file_id'))
                ->orWhereIn('id', DB::table('group_event_images')->whereIn('post_id', $eventIds)->select('file_id'))
                ->orWhereIn('id', DB::table('group_event_comment_images')->whereIn('post_id', $eventCommentIds)->select('file_id'))
                ->get()
                ->all();

            foreach ([
                (new GroupMessage)->getMorphClass() => DB::table('group_messages')->where('group_id', $locked->getKey())->select('id'),
                (new GroupTopicComment)->getMorphClass() => $topicCommentIds,
                (new GroupEventComment)->getMorphClass() => $eventCommentIds,
            ] as $alias => $ids) {
                Reaction::query()->where('reactable_type', $alias)->whereIn('reactable_id', $ids)->delete();
            }

            // `groups.file_id` is a mutable self-column: read under the lock, or an edit that just
            // replaced the image would orphan the new File.
            $image = $locked->image()->first();
            $locked->delete();

            return $image === null ? $files : [...$files, $image];
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
