<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * See docs/internals/file-storage.md, "Ownerless files".
 */
class PruneOwnerlessFilesCommand extends Command
{
    protected $signature = 'openpne:prune-ownerless-files
        {--days=7 : Only prune files stored at least this many days ago}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete files no record owns and nobody can read';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subDays(max(0, (int) $this->option('days')));
        $query = $this->ownerless($cutoff);

        $count = (clone $query)->count();
        $bytes = (int) (clone $query)->sum('byte_size');

        if ($count === 0) {
            $this->info('No ownerless files to prune.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$count} ownerless file(s), {$bytes} bytes, would be pruned.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $query->chunkById(200, function ($files) use (&$deleted): void {
            foreach ($files as $file) {
                // Deleted through the model so FileObserver removes the bytes and the image cache.
                $deleted += $file->delete() ? 1 : 0;
            }
        });

        $this->info("Pruned {$deleted} ownerless file(s), {$bytes} bytes.");

        return self::SUCCESS;
    }

    /** @return Builder<File> */
    private function ownerless(CarbonImmutable $cutoff): Builder
    {
        return File::query()
            ->whereNull('related_entity_type')
            ->where(fn (Builder $q) => $q->whereNull('explicit_visibility')->orWhere('explicit_visibility', '!=', File::VISIBILITY_PUBLIC))
            ->where('files.created_at', '<=', $cutoff);
    }
}
