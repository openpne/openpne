<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * File storage backend for self-hosters who keep bytes on a Laravel filesystem
 * disk (local FS or S3) instead of the DB. Bytes are addressed by File::name (the
 * backend-agnostic storage key); file_id stays internal to the DB-BLOB backend.
 *
 * The disk is one declared in config/filesystems.php and selected by
 * openpne.files.disk (anything other than 'blob'). Register an s3 disk there
 * before pointing openpne.files.disk at it.
 */
class DiskFileStorage implements FileStorage
{
    public function __construct(private readonly string $disk) {}

    public function writeStream(File $file, $stream): void
    {
        // Pin private visibility. Delivery always streams through the app controller
        // (never a bare disk URL), but an object must still not be world-readable if a
        // disk or route is later misconfigured.
        if (Storage::disk($this->disk)->writeStream($file->name, $stream, ['visibility' => 'private']) === false) {
            throw new RuntimeException("Unable to write file [{$file->name}] to disk [{$this->disk}].");
        }
    }

    public function readStream(File $file)
    {
        $stream = Storage::disk($this->disk)->readStream($file->name);

        if ($stream === null) {
            throw new RuntimeException("No stored bytes for file [{$file->name}] on disk [{$this->disk}].");
        }

        return $stream;
    }

    public function delete(File $file): void
    {
        // Storage::delete is idempotent (a missing path is not an error), matching
        // the contract and the DB-BLOB backend.
        Storage::disk($this->disk)->delete($file->name);
    }

    public function exists(File $file): bool
    {
        return Storage::disk($this->disk)->exists($file->name);
    }
}
