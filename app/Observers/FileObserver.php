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
     * FileStorage::delete must be idempotent. The cache goes first and its refusal stops the delete, the
     * stored bytes still in place (docs/internals/file-storage.md, "Deleting a file").
     *
     * @throws ImageCachePurgeException
     */
    public function deleting(File $file): void
    {
        if (! $this->imageCache->purge($file)) {
            throw new ImageCachePurgeException("The image cache refused to drop the derived files of file [{$file->getKey()}].");
        }

        $this->storage->delete($file);
    }

    /** A variant published between the purge above and the commit is removed here; its failure cannot undo the delete, so it is reported. */
    public function deleted(File $file): void
    {
        DB::afterCommit(function () use ($file): void {
            if (! $this->imageCache->purge($file)) {
                report(new ImageCachePurgeException("Derived files of deleted file [{$file->getKey()}] were left on the image cache."));
            }
        });
    }
}
