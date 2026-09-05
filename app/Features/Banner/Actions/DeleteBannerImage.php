<?php

namespace App\Features\Banner\Actions;

use App\Models\BannerImage;
use Illuminate\Support\Facades\DB;

/**
 * The FK cascade runs files→banner_images and not the reverse, so dropping the row leaves its File
 * behind to be deleted here. The row is locked and dropped inside one transaction so a concurrent
 * replace cannot orphan its File.
 */
class DeleteBannerImage
{
    public function __invoke(BannerImage $image): void
    {
        $file = DB::transaction(function () use ($image): ?\App\Models\File {
            $locked = $image->newQuery()->whereKey($image->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $file = $locked->file()->first();
            $locked->delete();

            return $file;
        });

        // After the commit: a disk backend's byte deletion cannot be rolled back.
        $file?->delete();
    }
}
