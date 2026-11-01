<?php

namespace App\Console\Commands;

use App\Support\PruneOAuth;
use Illuminate\Console\Command;

class PruneOAuthCommand extends Command
{
    protected $signature = 'openpne:prune-oauth';

    protected $description = 'Delete OAuth codes, tokens and clients the sign-in flow no longer needs';

    public function handle(): int
    {
        $this->info(PruneOAuth::run().' rows removed.');

        return self::SUCCESS;
    }
}
