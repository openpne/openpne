<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Files\CanonicalUnavailableException;
use App\Files\ImageCache;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * A picture the processor refused stays refused until `--retry-failed` or `rebuild` asks again, so
 * raising a limit does nothing by itself (docs/internals/images.md, "files.width / files.height").
 */
class ImageCacheCommand extends Command
{
    protected $signature = 'openpne:image-cache
        {action : status, warm or rebuild}
        {--retry-failed : with warm, ask the processor again about the pictures it once refused}';

    protected $description = 'Inspect, warm or rebuild the canonical of every stored picture';

    private const REFUSED_TO_LIST = 20;

    public function handle(ImageCache $cache): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($cache),
            'warm' => $this->warm($cache, retryFailed: (bool) $this->option('retry-failed'), rebuild: false),
            'rebuild' => $this->warm($cache, retryFailed: true, rebuild: true),
            default => $this->unknownAction(),
        };
    }

    private function status(ImageCache $cache): int
    {
        $total = $warm = $refused = $cold = 0;
        $reasons = [];

        $this->rasters()->chunkById(200, function (Collection $chunk) use ($cache, &$total, &$warm, &$refused, &$cold, &$reasons): void {
            foreach ($chunk as $file) {
                $total++;

                if ($cache->hasCanonical($file)) {
                    $warm++;
                } elseif (($reason = $cache->refusal($file)) !== null) {
                    $refused++;
                    if (count($reasons) < self::REFUSED_TO_LIST) {
                        $reasons[] = "  #{$file->id} {$file->name}: {$reason}";
                    }
                } else {
                    $cold++;
                }
            }
        });

        $this->line("Pictures: {$total}");
        $this->line("  canonical: {$warm}");
        $this->line("  cold:      {$cold}  (made on first view, or by `openpne:image-cache warm`)");
        $this->line("  refused:   {$refused}".($refused > 0 ? '  (`openpne:image-cache warm --retry-failed` asks again, e.g. after raising a limit)' : ''));

        foreach ($reasons as $line) {
            $this->line($line);
        }
        if ($refused > count($reasons)) {
            $this->line('  … '.($refused - count($reasons)).' more');
        }

        return self::SUCCESS;
    }

    private function warm(ImageCache $cache, bool $retryFailed, bool $rebuild): int
    {
        $warmed = $sized = $refused = $skipped = $unavailable = $unreadable = 0;

        $this->rasters()->chunkById(200, function (Collection $chunk) use ($cache, $retryFailed, $rebuild, &$warmed, &$sized, &$refused, &$skipped, &$unavailable, &$unreadable): void {
            foreach ($chunk as $file) {
                if ($rebuild) {
                    $cache->purgeGeneration($file);
                } elseif ($cache->hasCanonical($file)) {
                    $sized += $this->recordSize($cache, $file) ? 1 : 0;

                    continue;
                } elseif ($cache->refusal($file) !== null) {
                    if (! $retryFailed) {
                        $skipped++;

                        continue;
                    }
                    $cache->forgetRefusal($file);
                }

                try {
                    $cache->canonical($file);
                } catch (CanonicalUnavailableException) {
                    $refused++;

                    continue;
                } catch (ImageProcessorUnavailableException) {
                    $unavailable++;

                    continue;
                } catch (Throwable) {
                    // The bytes are gone or unreadable: nothing to decode and nothing to remember.
                    $unreadable++;

                    continue;
                }

                $warmed++;
                $sized += $this->recordSize($cache, $file) ? 1 : 0;
            }
        });

        $this->info(sprintf(
            'Warmed %d picture(s), recorded %d size(s); %d refused, %d skipped as refused before (pass --retry-failed), %d unavailable (processor down), %d unreadable.',
            $warmed, $sized, $refused, $skipped, $unavailable, $unreadable,
        ));

        return $unavailable > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Fills files.width/height from the canonical when a row has none; true when it wrote. */
    private function recordSize(ImageCache $cache, File $file): bool
    {
        if ($file->width !== null && $file->height !== null) {
            return false;
        }

        try {
            $size = @getimagesizefromstring($cache->canonical($file));
        } catch (Throwable) {
            return false;
        }

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return false;
        }

        $file->update(['width' => (int) $size[0], 'height' => (int) $size[1]]);

        return true;
    }

    /** @return Builder<File> */
    private function rasters(): Builder
    {
        return File::query()->whereIn('type', ImageSpec::rasterMimes());
    }

    private function unknownAction(): int
    {
        $this->error('Unknown action ['.$this->argument('action').']; expected status, warm or rebuild.');

        return self::FAILURE;
    }
}
