<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * See docs/internals/file-storage.md, "Ownerless files".
 */
class PruneOwnerlessFilesCommand extends Command
{
    protected $signature = 'openpne:prune-ownerless-files
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete files no record owns and nobody can read';

    public function handle(): int
    {
        $query = $this->ownerless();

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No ownerless files to prune.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $bytes = (int) (clone $query)->sum('byte_size');
            $this->info("{$count} ownerless file(s), {$bytes} bytes, would be pruned.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $bytes = 0;
        $query->chunkById(200, function ($files) use (&$deleted, &$bytes): void {
            foreach ($files as $file) {
                // Deleted through the model so FileObserver removes the bytes and the image cache.
                if ($file->delete()) {
                    $deleted++;
                    $bytes += (int) $file->byte_size;
                }
            }
        });

        $this->info("Pruned {$deleted} ownerless file(s), {$bytes} bytes.");

        return self::SUCCESS;
    }

    /** @return Builder<File> */
    private function ownerless(): Builder
    {
        return File::query()
            ->whereNull('related_entity_type')
            ->where(fn (Builder $q) => $q->whereNull('explicit_visibility')->orWhere('explicit_visibility', '!=', File::VISIBILITY_PUBLIC))
            // The admin_* names are OpenPNE 3 admin images that custom HTML/CSS reference by URL; the escape is not a backslash because MySQL reads one in a literal where SQLite does not.
            ->whereRaw("`name` NOT LIKE 'admin!_%' ESCAPE '!'");
    }
}
