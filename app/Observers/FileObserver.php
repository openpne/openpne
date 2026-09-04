<?php

namespace App\Observers;

use App\Files\FileStorage;
use App\Files\ImageCache;
use App\Models\File;

class FileObserver
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageCache $imageCache,
    ) {}

    /**
     * Runs before the SQL DELETE, whose FK cascade also drops the DB-BLOB backend's file_bin row, so
     * FileStorage::delete must be idempotent. On a disk backend this is the only thing that removes
     * the physical bytes.
     */
    public function deleting(File $file): void
    {
        $this->storage->delete($file);
        $this->imageCache->purge($file);
    }
}
