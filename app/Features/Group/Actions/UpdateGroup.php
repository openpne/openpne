<?php

namespace App\Features\Group\Actions;

use App\Features\Group\GroupMembership;
use App\Features\Group\Data\GroupFormData;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Files\PostImages;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\File;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class UpdateGroup
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Edit a group's settings and manage its single top
     * image: replace it with $image, or clear it when $removeImage is set. A new image's bytes are
     * rollback-safe; the replaced/removed File's bytes (irreversible on a disk backend) are purged
     * only after commit.
     */
    public function __invoke(Member $actor, Group $group, GroupFormData $data, ?UploadedFile $image = null, bool $removeImage = false): void
    {
        if (! GroupMembership::canManage($group, $actor)) {
            throw new GroupActionException(GroupActionFailure::NotManager);
        }

        // Keeping the group's current category is always allowed, even if it is admin-only —
        // only switching to a non-member-creatable category is refused.
        $keepsCurrentCategory = $data->categoryId === $group->group_category_id;
        if (! $keepsCurrentCategory && ! GroupCategory::memberCreatable($data->categoryId)) {
            throw new GroupActionException(GroupActionFailure::CategoryNotAllowed);
        }

        $replaced = $this->images->compensating(function (callable $store) use ($actor, $group, $data, $image, $removeImage): ?File {
            // Re-read under the lock and work off $locked: file_id is a mutable column on this row, so
            // the passed-in instance may carry a value already overwritten by a concurrent edit that
            // won the lock first. Reading the prior File off the stale value would miss that edit's
            // image and orphan its bytes. (UpdateTopic is safe without this because it reads images by
            // the immutable post_id, not a self-column.)
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            // Re-check management under the lock (see AcceptAdminTransfer): a transfer accepted after
            // page load could have demoted this ex-manager. No image is stored yet, so the throw just
            // rolls the transaction back with nothing to purge.
            if (! GroupMembership::canManage($locked, $actor)) {
                throw new GroupActionException(GroupActionFailure::NotManager);
            }

            $locked->update([
                'name' => $data->name,
                'description' => $data->description,
                'register_policy' => $data->registerPolicy,
                'group_category_id' => $data->categoryId,
                'is_join_notification_enabled' => $data->isJoinNotificationEnabled,
                'topic_read_access' => $data->topicReadAccess,
                'topic_post_authority' => $data->topicPostAuthority,
            ]);

            // A new upload wins over a remove flag. Capture the prior File (if any) to purge after commit.
            if ($image !== null) {
                $previous = $locked->image()->first();
                $file = $store($image, 'community', (int) $locked->getKey());
                $locked->update(['file_id' => $file->getKey()]);

                return $previous;
            }

            if ($removeImage) {
                $previous = $locked->image()->first();
                $locked->update(['file_id' => null]);

                return $previous;
            }

            return null;
        });

        $replaced?->delete(); // deleting the File purges its bytes
    }
}
