<?php

namespace App\Console\Commands;

use App\Compat\Openpne3Routes;
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
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            $module = $parity->module();
            $this->line("## `{$module}`");
            $this->line('');
            // 🔗 = GET-reachable, under the URL-compatibility contract (bookmarks/mail/links).
            // ↪ = POST-only form submit, tracked for completeness but outside URL compatibility.
            // 🆕 = no named OpenPNE 3 route (reached via the global fallback, or OpenPNE 4-native).
            $this->line('| scope | OpenPNE 3 route | OpenPNE 3 URL | method | Laravel route | Laravel URL | note |');
            $this->line('|---|---|---|---|---|---|---|');

            foreach ($parity->maps() as $map) {
                $laravelUrl = Route::getRoutes()->getByName($map->laravelRoute)?->uri() ?? '(missing)';
                $scope = $this->scope($inventory, $module, $map->op3Route);
                $op3Route = $map->op3Route === null ? '—' : "`{$map->op3Route}`";
                $op3Url = $map->op3Url === null ? '—' : "`{$map->op3Url}`";
                $note = $map->note ?? '';
                $this->line("| {$scope} | {$op3Route} | {$op3Url} | {$map->method} | `{$map->laravelRoute}` | `/{$laravelUrl}` | {$note} |");
            }

            if ($parity->gaps() !== []) {
                $this->line('');
                $this->line('Not ported:');
                foreach ($parity->gaps() as $route => $reason) {
                    $scope = $this->scope($inventory, $module, $route);
                    $this->line("- {$scope} `{$route}` — {$reason}");
                }
            }

            $this->line('');
        }

        $this->line('Scope: 🔗 URL-compatibility contract (GET-reachable) · ↪ completeness only (POST form submit) · 🆕 no named OpenPNE 3 route');

        return self::SUCCESS;
    }

    private function scope(Openpne3Routes $inventory, string $module, ?string $op3Route): string
    {
        if ($op3Route === null) {
            return '🆕';
        }

        return $inventory->isUrlCompatible($module, $op3Route) ? '🔗' : '↪';
    }
}
