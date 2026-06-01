<?php

namespace App\Observers;

use App\Files\FileStorage;
use App\Models\File;

class FileObserver
{
    public function __construct(private readonly FileStorage $storage) {}

    /**
     * The single cleanup hub for a deleted file. Removes the stored bytes; for the
     * DB-BLOB backend the file_bin row also goes via the FK cascade, so this runs
     * first and FileStorage::delete must be idempotent. For disk backends this is
     * the only thing that removes the physical bytes.
     *
     * Future derived data (e.g. ImageCache::purge for thumbnails) hooks in here so
     * file deletion has one place that fans out to all stored artefacts.
     */
    public function deleting(File $file): void
    {
        $this->storage->delete($file);
    }
}
