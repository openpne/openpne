<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Files\CanonicalUnavailableException;
use App\Files\ImageCache;
use App\Files\ImageLadder;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessorUnavailableException;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Draws a posted picture's still rungs before anyone asks for one, and only where a worker takes
 * the queue (docs/internals/images.md, "Drawn when posted").
 */
class DrawImageVariants implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A rung left undrawn is drawn by its first view, so nothing here is worth a retry. */
    public int $tries = 1;

    public function __construct(public readonly int $fileId) {}

    /** afterCommit: the upload's row is written inside the post's transaction, which may yet roll back. */
    public static function for(File $file): void
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');

        // Either driver would draw in the posting request's own process, ahead of the notifications queued after it.
        if (in_array($driver, ['sync', 'deferred'], true) || $file->imageFormat() === null) {
            return;
        }

        self::dispatch((int) $file->getKey())->afterCommit();
    }

    public function handle(ImageCache $cache): void
    {
        $file = File::query()->find($this->fileId);

        if ($file === null) {
            return;
        }

        foreach (ImageLadder::stills($file) as [$transform, $format]) {
            // ImageCache::bytes() removes what it published for a deleted file; this only spares drawing the rest.
            if (! File::query()->whereKey($this->fileId)->exists()) {
                return;
            }

            try {
                $cache->bytes($file, $transform, $format);
            } catch (CanonicalUnavailableException|ImageProcessingException|ImageProcessorUnavailableException) {
                return;
            }
        }
    }
}
