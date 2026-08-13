<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Data\GroupFormData;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupRole;
use App\Files\PostImages;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateGroup
{
    public function __construct(private readonly PostImages $images) {}

    public function __invoke(Member $creator, GroupFormData $data, ?UploadedFile $image = null): Group
    {
        if (! GroupCategory::memberCreatable($data->categoryId)) {
            throw new GroupActionException(GroupActionFailure::CategoryNotAllowed);
        }

        // compensating() (not a bare transaction) so a failed top-image byte write rolls back
        // wholesale without orphaning bytes on a disk backend.
        return $this->images->compensating(function (callable $store) use ($creator, $data, $image): Group {
            $group = Group::create([
                'name' => $data->name,
                'description' => $data->description,
                'register_policy' => $data->registerPolicy,
                'group_category_id' => $data->categoryId,
                'is_join_notification_enabled' => $data->isJoinNotificationEnabled,
                'topic_read_access' => $data->topicReadAccess,
                'topic_post_authority' => $data->topicPostAuthority,
            ]);

            // The creator is the sole admin (one admin per group).
            $group->members()->create([
                'member_id' => $creator->getKey(),
                'role' => GroupRole::Admin,
            ]);

            if ($image !== null) {
                $file = $store($image, 'group', (int) $group->getKey());
                $group->update(['file_id' => $file->getKey()]);
            }

            return $group;
        });
    }
}
