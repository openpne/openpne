<?php

namespace App\Console\Commands;

use App\Files\ImageCache;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Reads each size off the canonical, so a run also warms the canonical of every row it visits
 * (docs/internals/images.md, "files.width / files.height").
 */
class BackfillImageDimensionsCommand extends Command
{
    protected $signature = 'openpne:backfill-image-dimensions';

    protected $description = 'Record the pixel dimensions of stored images that have none';

    public function handle(ImageCache $cache): int
    {
        $updated = 0;
        $skipped = 0;

        File::query()
            ->whereNull('width')
            ->where('type', 'like', 'image/%')
            ->chunkById(200, function (Collection $chunk) use ($cache, &$updated, &$skipped): void {
                foreach ($chunk as $file) {
                    $size = $this->dimensions($cache, $file);

                    if ($size === null) {
                        $skipped++;

                        continue;
                    }

                    $file->update(['width' => $size[0], 'height' => $size[1]]);
                    $updated++;
                }
            });

        $this->info("Recorded dimensions for {$updated} file(s), skipped {$skipped} unreadable one(s).");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int}|null */
    private function dimensions(ImageCache $cache, File $file): ?array
    {
        try {
            $size = @getimagesizefromstring($cache->canonical($file));
        } catch (Throwable) {
            return null;
        }

        return $size !== false && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
    }
}
