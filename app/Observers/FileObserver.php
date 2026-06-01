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
     * The single cleanup hub for a deleted file. Removes the stored bytes; for the
     * DB-BLOB backend the file_bin row also goes via the FK cascade, so this runs
     * first and FileStorage::delete must be idempotent. For disk backends this is
     * the only thing that removes the physical bytes. Cached thumbnails are purged
     * too, so file deletion fans out to every stored artefact (both idempotent).
     */
    public function deleting(File $file): void
    {
        $this->storage->delete($file);
        $this->imageCache->purge($file);
    }
}
