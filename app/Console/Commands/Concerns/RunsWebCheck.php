<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\SiteCheck\Finding;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\WebCheck;

trait RunsWebCheck
{
    /**
     * Removes the check's files on SIGINT, SIGTERM or SIGHUP as well as in its own `finally`.
     *
     * @return list<Finding>
     */
    private function runWebCheck(SiteLayout $layout, ?string $parentUrl = null): array
    {
        $web = $this->laravel->make(WebCheck::class, ['layout' => $layout]);
        // Laravel traps wherever pcntl is loaded, and fails where disable_functions holds what its trap calls.
        if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler') && function_exists('pcntl_async_signals')) {
            $this->trap(fn (): array => [SIGINT, SIGTERM, SIGHUP], function (int $signal) use ($web): never {
                $web->cleanUp();
                exit(128 + $signal);
            });
        }

        try {
            return $web->run($parentUrl);
        } finally {
            $this->untrap();
        }
    }
}
