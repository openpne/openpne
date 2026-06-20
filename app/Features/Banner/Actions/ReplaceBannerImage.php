<?php

namespace App\Features\Banner\Actions;

use App\Files\PostImages;
use App\Models\BannerImage;
use Illuminate\Http\UploadedFile;

/**
 * Swaps a banner image's file for a newly uploaded one.
 *
 * Mirrors SetAvatar's ordering: the new bytes are stored and the file_id repointed inside the
 * transaction (compensating purges the new bytes if it throws), and the replaced File — bytes
 * irreversible on a disk backend — is purged only after commit, so a failed swap keeps the old image.
 */
class ReplaceBannerImage
{
    public function __construct(private readonly PostImages $images) {}

    public function __invoke(BannerImage $image, UploadedFile $upload): BannerImage
    {
        $replaced = $image->file;

        $this->images->compensating(function (callable $store) use ($image, $upload): void {
            $file = $store($upload, 'bannerImage', $image->getKey());
            $image->update(['file_id' => $file->getKey()]);
        });

        $replaced?->delete();

        return $image->refresh();
    }
}
