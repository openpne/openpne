<?php

namespace App\Observers;

use App\Files\FileStorage;
use App\Files\ImageCache;
use App\Files\ImageCachePurgeException;
use App\Models\File;
use Illuminate\Support\Facades\DB;

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

    /**
     * A variant published between the purge above and the commit is removed here. Never thrown: callers
     * delete files after their own commit, where an exception would strand the rest (docs/internals/file-storage.md, "Deleting a file").
     */
    public function deleted(File $file): void
    {
        DB::afterCommit(function () use ($file): void {
            // A publish that overlaps one attempt makes it fail though nothing is wrong with the disk.
            foreach ([1, 2, 3] as $attempt) {
                if ($this->imageCache->purge($file)) {
                    return;
                }
            }

            report(new ImageCachePurgeException("Derived files of deleted file [{$file->getKey()}] may be left on the image cache."));
        });
    }
}
