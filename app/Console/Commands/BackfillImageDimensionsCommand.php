<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Files\FileStorage;
use App\Files\ImageDimensions;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * See docs/internals/images.md "files.width / files.height".
 */
class BackfillImageDimensionsCommand extends Command
{
    protected $signature = 'openpne:backfill-image-dimensions';

    protected $description = 'Record the pixel dimensions of stored images that have none';

    public function handle(FileStorage $storage): int
    {
        $updated = 0;
        $skipped = 0;

        File::query()
            ->whereNull('width')
            ->where('type', 'like', 'image/%')
            ->chunkById(200, function (Collection $chunk) use ($storage, &$updated, &$skipped): void {
                foreach ($chunk as $file) {
                    $size = $this->dimensions($storage, $file);

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
    private function dimensions(FileStorage $storage, File $file): ?array
    {
        try {
            $stream = $storage->readStream($file);
            $bytes = (string) stream_get_contents($stream);
            fclose($stream);
        } catch (Throwable) {
            return null;
        }

        return ImageDimensions::fromBytes($bytes);
    }
}
