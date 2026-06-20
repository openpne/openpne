<?php

namespace App\Features\Banner\Actions;

use App\Models\BannerImage;
use Illuminate\Support\Facades\DB;

/**
 * Removes a banner image and its stored File.
 *
 * The banner_use_images placements cascade off the row, but the File does not (the FK cascade runs
 * files→banner_images, not the reverse), so it is deleted explicitly. Mirrors RemoveAvatar: the row
 * is dropped inside the transaction and the File — bytes irreversible on a disk backend — is purged
 * after commit.
 */
class DeleteBannerImage
{
    public function __invoke(BannerImage $image): void
    {
        $file = $image->file;

        DB::transaction(function () use ($image): void {
            $image->delete();
        });

        $file?->delete();
    }
}
