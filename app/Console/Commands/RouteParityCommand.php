<?php

namespace App\Console\Commands;

use App\Compat\RouteParityRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/** Renders the OpenPNE 3 → Laravel route parity (all modules) as Markdown. */
class RouteParityCommand extends Command
{
    protected $signature = 'openpne:route-parity';

    protected $description = 'Render the OpenPNE 3 → Laravel route parity as Markdown';

    public function handle(): int
    {
        foreach (RouteParityRegistry::all() as $parity) {
            $this->line("## `{$parity->module()}`");
            $this->line('');
            $this->line('| OpenPNE 3 route | OpenPNE 3 URL | method | Laravel route | Laravel URL | note |');
            $this->line('|---|---|---|---|---|---|');

            foreach ($parity->maps() as $map) {
                $laravelUrl = Route::getRoutes()->getByName($map->laravelRoute)?->uri() ?? '(missing)';
                $note = $map->note ?? '';
                $this->line("| `{$map->op3Route}` | `{$map->op3Url}` | {$map->method} | `{$map->laravelRoute}` | `/{$laravelUrl}` | {$note} |");
            }

            if ($parity->gaps() !== []) {
                $this->line('');
                $this->line('Not ported:');
                foreach ($parity->gaps() as $route => $reason) {
                    $this->line("- `{$route}` — {$reason}");
                }
            }

            $this->line('');
        }

        return self::SUCCESS;
    }
}
