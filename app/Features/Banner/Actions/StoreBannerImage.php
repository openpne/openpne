<?php

namespace App\Features\Banner\Actions;

use App\Files\PostImages;
use App\Models\BannerImage;
use Illuminate\Http\UploadedFile;

/**
 * Adds an image to the banner pool from an uploaded file and associates it with placements.
 *
 * Wrapped in PostImages::compensating so a failure after the bytes are stored (creating the row,
 * back-linking the File, syncing placements) does not orphan them on a disk backend. The File is
 * stored first (banner_images.file_id is NOT NULL), then the owner id is back-linked once the row
 * exists, so FilePolicy can resolve the image as its (public) owner.
 */
class StoreBannerImage
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  list<int>  $placementIds  banners the image is shown in
     */
    public function __invoke(UploadedFile $upload, ?string $url, ?string $name, array $placementIds = []): BannerImage
    {
        return $this->images->compensating(function (callable $store) use ($upload, $url, $name, $placementIds): BannerImage {
            $file = $store($upload, 'bannerImage', 0);

            $image = BannerImage::create([
                'file_id' => $file->getKey(),
                'url' => $url,
                'name' => $name,
            ]);

            $file->update(['related_entity_id' => $image->getKey()]);
            $image->banners()->sync($placementIds);

            return $image;
        });
    }
}
