<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Records files.width / files.height for the image rows that carry none.
 *
 * An upload measures itself, so this is for the rows that never passed through it: files stored
 * before the columns existed, and files the OpenPNE 3 upgrade brings in — run it after an upgrade.
 *
 * A row whose bytes are gone or do not decode is left NULL and the run continues, because a
 * corrupt file must not stall the rest; consumers already treat NULL as unknown
 * (docs/internals/images.md). Idempotent: only NULL rows are selected, so re-running it picks up
 * exactly what an interrupted run did not reach.
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

        $size = @getimagesizefromstring($bytes);

        // A zero side is no size at all (FileUploader applies the same rule at ingestion).
        return $size === false || $size[0] < 1 || $size[1] < 1 ? null : [$size[0], $size[1]];
    }
}
