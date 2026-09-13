<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Files\CanonicalUnavailableException;
use App\Files\ImageCache;
use App\Files\ImageCachePublishException;
use App\Files\ImageProcessorUnavailableException;
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

    private const ROWS_TO_LIST = 20;

    /** Refusals in a row that end the run: a full or read-only cache disk, not one row's directory. */
    private const UNWRITTEN_STREAK_TO_STOP = 3;

    public function handle(ImageCache $cache): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($cache),
            'warm' => $this->warm($cache, retryFailed: (bool) $this->option('retry-failed'), rebuild: false),
            'rebuild' => $this->warm($cache, retryFailed: false, rebuild: true),
            default => $this->unknownAction(),
        };
    }

    private function status(ImageCache $cache): int
    {
        $total = $warm = $refused = $cold = $unshown = 0;
        $refusedRows = $unshownRows = [];

        $this->images()->chunkById(200, function (Collection $chunk) use ($cache, &$total, &$warm, &$refused, &$cold, &$unshown, &$refusedRows, &$unshownRows): void {
            foreach ($chunk as $file) {
                $total++;

                if ($file->imageFormat() === null) {
                    $unshown++;
                    $this->remember($unshownRows, $file, (string) $file->type);
                } elseif ($cache->hasCanonical($file)) {
                    $warm++;
                } elseif (($reason = $cache->refusal($file)) !== null) {
                    $refused++;
                    $this->remember($refusedRows, $file, $reason);
                } else {
                    $cold++;
                }
            }
        });

        $this->line("Pictures: {$total}");
        $this->line("  canonical: {$warm}");
        $this->line("  cold:      {$cold}  (made on first view, or by `openpne:image-cache warm`)");
        $this->line("  refused:   {$refused}".($refused > 0 ? '  (`openpne:image-cache warm --retry-failed` asks again, e.g. after raising a limit)' : ''));
        $this->listRows($refusedRows, $refused);
        $this->line("  unshown:   {$unshown}".($unshown > 0 ? '  (stored under an image type this version does not show as a picture)' : ''));
        $this->listRows($unshownRows, $unshown);

        return self::SUCCESS;
    }

    private function warm(ImageCache $cache, bool $retryFailed, bool $rebuild): int
    {
        $n = ['done' => 0, 'sized' => 0, 'refused' => 0, 'skipped' => 0, 'unavailable' => 0, 'unreadable' => 0, 'unwritten' => 0, 'unshown' => 0];
        $failedRows = $unshownRows = [];
        $streak = 0;
        $stopped = false;

        $this->images()->chunkById(200, function (Collection $chunk) use ($cache, $retryFailed, $rebuild, &$n, &$failedRows, &$unshownRows, &$streak, &$stopped): bool {
            foreach ($chunk as $file) {
                if ($file->imageFormat() === null) {
                    $n['unshown']++;
                    $this->remember($unshownRows, $file, (string) $file->type);

                    continue;
                }

                if (! $rebuild && $cache->hasCanonical($file)) {
                    $n['sized'] += $this->recordSize($file, fn (): string => $cache->canonical($file), force: false) ? 1 : 0;

                    continue;
                }

                if (! $rebuild && ! $retryFailed && $cache->refusal($file) !== null) {
                    $n['skipped']++;

                    continue;
                }

                try {
                    $canonical = $rebuild ? $cache->rebuild($file) : $cache->warm($file);
                } catch (CanonicalUnavailableException) {
                    $n['refused']++;

                    continue;
                } catch (ImageProcessorUnavailableException) {
                    $n['unavailable']++;

                    continue;
                } catch (ImageCachePublishException $e) {
                    // Rebuild has discarded before it writes, so a disk refusing everything must not be walked to the end.
                    $n['unwritten']++;
                    $this->remember($failedRows, $file, $e->getMessage());

                    if (++$streak >= self::UNWRITTEN_STREAK_TO_STOP) {
                        $stopped = true;

                        return false;
                    }

                    continue;
                } catch (Throwable $e) {
                    $n['unreadable']++;
                    $this->remember($failedRows, $file, $e->getMessage());

                    continue;
                }

                $streak = 0;
                $n['done']++;
                $n['sized'] += $this->recordSize($file, fn (): string => $canonical, force: true) ? 1 : 0;
            }

            return true;
        });

        $this->info(sprintf('%s %d picture(s), recorded %d size(s).', $rebuild ? 'Rebuilt' : 'Warmed', $n['done'], $n['sized']));
        $this->line("  refused:     {$n['refused']}  (remembered; `warm --retry-failed` asks again)");
        $this->line("  skipped:     {$n['skipped']}  (refused before; pass --retry-failed)");
        $this->line("  unavailable: {$n['unavailable']}  (processor down; nothing remembered)");
        $this->line("  unreadable:  {$n['unreadable']}  (the stored bytes could not be read)");
        $this->line("  unwritten:   {$n['unwritten']}  (the cache disk refused the write".($stopped ? '; the run stopped after '.self::UNWRITTEN_STREAK_TO_STOP.' in a row' : '').')');
        $this->listRows($failedRows, $n['unreadable'] + $n['unwritten']);
        $this->line("  unshown:     {$n['unshown']}  (stored under an image type this version does not show as a picture)");
        $this->listRows($unshownRows, $n['unshown']);

        return $n['unavailable'] + $n['unreadable'] + $n['unwritten'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param  callable(): string  $canonical */
    private function recordSize(File $file, callable $canonical, bool $force): bool
    {
        if (! $force && $file->width !== null && $file->height !== null) {
            return false;
        }

        try {
            $size = @getimagesizefromstring($canonical());
        } catch (Throwable) {
            return false;
        }

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return false;
        }

        if ($file->width === (int) $size[0] && $file->height === (int) $size[1]) {
            return false;
        }

        $file->update(['width' => (int) $size[0], 'height' => (int) $size[1]]);

        return true;
    }

    /** @return Builder<File> */
    private function images(): Builder
    {
        return File::query()->where('type', 'like', 'image/%');
    }

    /** @param  list<string>  $listed */
    private function remember(array &$listed, File $file, string $text): void
    {
        if (count($listed) < self::ROWS_TO_LIST) {
            $listed[] = "  #{$file->id} {$file->name}: {$text}";
        }
    }

    /** @param  list<string>  $listed */
    private function listRows(array $listed, int $total): void
    {
        foreach ($listed as $line) {
            $this->line($line);
        }

        if ($total > count($listed)) {
            $this->line('  … '.($total - count($listed)).' more');
        }
    }

    private function unknownAction(): int
    {
        $this->error('Unknown action ['.$this->argument('action').']; expected status, warm or rebuild.');

        return self::FAILURE;
    }
}
