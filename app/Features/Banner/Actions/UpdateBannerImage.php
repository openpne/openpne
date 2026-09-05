<?php

namespace App\Features\Banner\Actions;

use App\Files\PostImages;
use App\Models\BannerImage;
use Illuminate\Http\UploadedFile;

/**
 * The row is locked first so two concurrent edits serialize rather than leaving the superseded
 * upload's File orphaned as a public banner image.
 */
class UpdateBannerImage
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  list<int>|null  $placementIds  null leaves the current placements untouched
     */
    public function __invoke(BannerImage $image, ?string $url, ?string $name, ?array $placementIds = null, ?UploadedFile $upload = null): BannerImage
    {
        $replaced = $this->images->compensating(function (callable $store) use ($image, $url, $name, $placementIds, $upload): ?\App\Models\File {
            $locked = $image->newQuery()->whereKey($image->getKey())->lockForUpdate()->first();

            $locked->update(['url' => $url, 'name' => $name]);

            if ($placementIds !== null) {
                $locked->banners()->sync($placementIds);
            }

            if ($upload === null) {
                return null;
            }

            $replaced = $locked->file()->first();
            $file = $store($upload, 'bannerImage', $locked->getKey());
            $locked->update(['file_id' => $file->getKey()]);

            return $replaced;
        });

        // After the commit: a disk backend's byte deletion cannot be rolled back.
        $replaced?->delete();

        return $image->refresh();
    }
}
