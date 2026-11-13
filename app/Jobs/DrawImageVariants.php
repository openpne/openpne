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
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /** Lets a lone worker with an empty queue take what the post queues after its commit first. */
    private const HEAD_START_SECONDS = 10;

    public function __construct(public readonly int $fileId) {}

    /**
     * Queued once the post's transaction has committed, and a push the queue refuses is reported, never
     * thrown: the caller's compensation would take it for a rollback and delete a committed post's bytes.
     */
    public static function for(File $file): void
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');

        // These draw, or may fall back to drawing, in the posting request's own process or one spawned per picture.
        if (in_array($driver, ['sync', 'deferred', 'background', 'failover'], true) || $file->imageFormat() === null) {
            return;
        }

        $id = (int) $file->getKey();

        DB::afterCommit(fn () => rescue(fn () => app(Dispatcher::class)->dispatch((new self($id))->delay(self::HEAD_START_SECONDS))));
    }

    public function handle(ImageCache $cache): void
    {
        $file = File::query()->find($this->fileId);

        if ($file === null) {
            return;
        }

        foreach (ImageLadder::stills($file) as [$transform, $format]) {
            try {
                $cache->bytes($file, $transform, $format);
            } catch (CanonicalUnavailableException|ImageProcessingException|ImageProcessorUnavailableException) {
                return;
            } catch (Throwable $e) {
                // A delete takes the stored bytes and the canonical with it, and reading either then throws whatever the backend throws.
                if (File::query()->whereKey($this->fileId)->exists()) {
                    throw $e;
                }

                return;
            }
        }
    }
}
