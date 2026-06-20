<?php

namespace App\Features\Banner\Actions;

use App\Files\PostImages;
use App\Models\BannerImage;
use Illuminate\Http\UploadedFile;

/**
 * Applies one admin edit of a banner image — link, label, placements, and optionally a replacement
 * file — atomically.
 *
 * Everything runs in one compensating transaction so a failed image swap rolls back the metadata too
 * (no half-saved edit). The row is locked first (like SetAvatar) so two concurrent edits/replaces
 * serialize instead of leaving the superseded upload's File orphaned as a public banner image. The
 * replaced File — bytes irreversible on a disk backend — is purged only after commit.
 */
class UpdateBannerImage
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  list<int>  $placementIds
     */
    public function __invoke(BannerImage $image, ?string $url, ?string $name, array $placementIds, ?UploadedFile $upload = null): BannerImage
    {
        $replaced = $this->images->compensating(function (callable $store) use ($image, $url, $name, $placementIds, $upload): ?\App\Models\File {
            $locked = $image->newQuery()->whereKey($image->getKey())->lockForUpdate()->first();

            $locked->update(['url' => $url, 'name' => $name]);
            $locked->banners()->sync($placementIds);

            if ($upload === null) {
                return null;
            }

            // Read the current File under the lock so a concurrent replace can't be missed.
            $replaced = $locked->file()->first();
            $file = $store($upload, 'bannerImage', $locked->getKey());
            $locked->update(['file_id' => $file->getKey()]);

            return $replaced;
        });

        $replaced?->delete();

        return $image->refresh();
    }
}
