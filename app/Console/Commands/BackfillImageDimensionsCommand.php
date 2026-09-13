<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** The name the upgrade guide used to give; `openpne:image-cache warm` is the same run. */
class BackfillImageDimensionsCommand extends Command
{
    protected $signature = 'openpne:backfill-image-dimensions';

    protected $description = 'Alias of `openpne:image-cache warm`';

    protected $hidden = true;

    public function handle(): int
    {
        return $this->call('openpne:image-cache', ['action' => 'warm']);
    }
}
