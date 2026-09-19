<?php

namespace App\Features\Group\Actions;

use App\Features\Group\BoardSweep;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupEventComment;
use App\Models\GroupMessage;
use App\Models\GroupTopicComment;
use App\Models\Member;
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
        $fileIds = DB::transaction(function () use ($group): array {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return []; // already deleted by a concurrent request
            }
            $groupId = (int) $locked->getKey();

            BoardSweep::holdBoards($groupId);

            $topics = DB::table('group_topics')->where('group_id', $groupId)->select('id');
            $events = DB::table('group_events')->where('group_id', $groupId)->select('id');
            $topicComments = DB::table('group_topic_comments')->whereIn('group_topic_id', $topics)->select('id');
            $eventComments = DB::table('group_event_comments')->whereIn('group_event_id', $events)->select('id');

            // The transaction's first consistent read, so its snapshot is taken under the locks above.
            $fileIds = DB::table('files')
                ->whereIn('id', DB::table('group_message_images')
                    ->join('group_messages', 'group_messages.id', '=', 'group_message_images.group_message_id')
                    ->where('group_messages.group_id', $groupId)
                    ->select('group_message_images.file_id'))
                ->orWhereIn('id', DB::table('group_topic_images')->whereIn('post_id', $topics)->select('file_id'))
                ->orWhereIn('id', DB::table('group_topic_comment_images')->whereIn('post_id', $topicComments)->select('file_id'))
                ->orWhereIn('id', DB::table('group_event_images')->whereIn('post_id', $events)->select('file_id'))
                ->orWhereIn('id', DB::table('group_event_comment_images')->whereIn('post_id', $eventComments)->select('file_id'))
                ->pluck('id')
                ->all();

            BoardSweep::messages((new GroupMessage)->getMorphClass(), $groupId);
            BoardSweep::comments((new GroupTopicComment)->getMorphClass(), 'group_topic_comments', 'group_topic_id', (clone $topics)->orderBy('id')->pluck('id'));
            BoardSweep::comments((new GroupEventComment)->getMorphClass(), 'group_event_comments', 'group_event_id', (clone $events)->orderBy('id')->pluck('id'));

            // `groups.file_id` is a mutable self-column: read under the lock, or an edit that just
            // replaced the image would orphan the new File.
            $image = $locked->file_id;
            $locked->delete();

            return $image === null ? $fileIds : [...$fileIds, (int) $image];
        }, attempts: 3);

        BoardSweep::files($fileIds);
    }
}
