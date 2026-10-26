<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The disk is one declared in `config/filesystems.php` and named by `openpne.files.disk` — anything
 * other than `blob` (docs/internals/file-storage.md, "The two backends").
 */
class DiskFileStorage implements FileStorage
{
    public function __construct(private readonly string $disk) {}

    /** The same token-or-hash rule as the image cache, so an OpenPNE 3 name cannot fold onto another file's path. */
    private static function path(File $file): string
    {
        return ImageTransform::directory($file->name);
    }

    public function writeStream(File $file, $stream): void
    {
        // Visibility is pinned private because nothing addresses an object by disk URL, so a
        // world-readable one could only ever be reached around the policy.
        if (Storage::disk($this->disk)->writeStream(self::path($file), $stream, ['visibility' => 'private']) === false) {
            throw new RuntimeException("Unable to write file [{$file->name}] to disk [{$this->disk}].");
        }
    }

    public function readStream(File $file)
    {
        $stream = Storage::disk($this->disk)->readStream(self::path($file));

        if ($stream === null) {
            throw new RuntimeException("No stored bytes for file [{$file->name}] on disk [{$this->disk}].");
        }

        return $stream;
    }

    public function delete(File $file): void
    {
        Storage::disk($this->disk)->delete(self::path($file));
    }

    public function exists(File $file): bool
    {
        return Storage::disk($this->disk)->exists(self::path($file));
    }
}
