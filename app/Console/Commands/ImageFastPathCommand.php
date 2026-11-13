<?php

namespace App\Console\Commands;

use App\Files\ImageFastPath;
use Illuminate\Console\Command;

class ImageFastPathCommand extends Command
{
    protected $signature = 'openpne:image-fast-path {action=sync : sync}';

    protected $description = 'Publish or remove the image fast path file to match the current configuration';

    public function handle(): int
    {
        if ($this->argument('action') !== 'sync') {
            $this->error('Unknown action; expected sync.');

            return self::INVALID;
        }

        if (ImageFastPath::sync()) {
            $this->info('The image fast path is on: '.ImageFastPath::path());

            return self::SUCCESS;
        }

        if (ImageFastPath::desired() === null) {
            $this->info('The image fast path is off; its file is removed.');

            return self::SUCCESS;
        }

        $this->error('The image fast path file could not be written: '.ImageFastPath::path());

        return self::FAILURE;
    }
}
