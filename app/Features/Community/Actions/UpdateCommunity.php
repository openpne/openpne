<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Files\PostImages;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\File;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class UpdateCommunity
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Edit a community's settings and, OpenPNE 3-style (CommunityFileForm), manage its single top
     * image: replace it with $image, or clear it when $removeImage is set. A new image's bytes are
     * rollback-safe; the replaced/removed File's bytes (irreversible on a disk backend) are purged
     * only after commit.
     */
    public function __invoke(Member $actor, Community $community, CommunityFormData $data, ?UploadedFile $image = null, bool $removeImage = false): void
    {
        if (! CommunityMembership::canManage($community, $actor)) {
            throw new CommunityActionException(CommunityActionFailure::NotManager);
        }

        // Keeping the community's current category is always allowed, even if it is admin-only —
        // only switching to a non-member-creatable category is refused (OpenPNE 3 checkCreatable).
        $keepsCurrentCategory = $data->categoryId === $community->community_category_id;
        if (! $keepsCurrentCategory && ! CommunityCategory::memberCreatable($data->categoryId)) {
            throw new CommunityActionException(CommunityActionFailure::CategoryNotAllowed);
        }

        $replaced = $this->images->compensating(function (callable $store) use ($community, $data, $image, $removeImage): ?File {
            // Re-read under the lock and work off $locked: file_id is a mutable column on this row, so
            // the passed-in instance may carry a value already overwritten by a concurrent edit that
            // won the lock first. Reading the prior File off the stale value would miss that edit's
            // image and orphan its bytes. (UpdateTopic is safe without this because it reads images by
            // the immutable post_id, not a self-column.)
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            $locked->update([
                'name' => $data->name,
                'description' => $data->description,
                'register_policy' => $data->registerPolicy,
                'community_category_id' => $data->categoryId,
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
