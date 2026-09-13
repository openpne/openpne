<?php

declare(strict_types=1);

namespace App\Files\Imgproxy;

use App\Files\ImageProcessorUnavailableException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The directory the sidecar reads as `local://`: a file lives here for one request and is deleted in
 * the caller's finally, so anything older than STALE_AFTER is a request that died and is swept on the
 * next write.
 */
final class Spool
{
    public const STALE_AFTER = 3600;

    public function __construct(private readonly string $disk) {}

    /**
     * @throws ImageProcessorUnavailableException
     */
    public function put(string $bytes, string $format): string
    {
        $disk = $this->disk();
        $name = Str::random(32).'.'.$format;

        if (! $disk->put($name, $bytes)) {
            Log::error("The image spool disk [{$this->disk}] refused a write; imgproxy cannot be asked.");

            throw new ImageProcessorUnavailableException("The image spool disk [{$this->disk}] refused the write.");
        }

        $this->sweep($disk);

        return $name;
    }

    public function delete(string $name): void
    {
        $this->disk()->delete($name);
    }

    private function sweep(Filesystem $disk): void
    {
        $cutoff = time() - self::STALE_AFTER;

        foreach ($disk->files() as $path) {
            // The directory's own .gitignore is not a spooled file.
            if (! str_starts_with(basename($path), '.') && $disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
