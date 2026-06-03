<?php

namespace App\Console\Commands;

use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\RouteParityRegistry;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Renders the per-screen surface-element parity (the third axis: intra-screen content) as
 * Markdown. Route parity says a screen's URL resolves; this says how much of that screen's
 * OpenPNE 3 content the Classic adapter renders, and what is still missing or deferred.
 */
class ScreenParityCommand extends Command
{
    protected $signature = 'openpne:screen-parity';

    protected $description = 'Render the OpenPNE 3 → Classic per-screen content parity as Markdown';

    public function handle(): int
    {
        foreach (RouteParityRegistry::all() as $parity) {
            if ($parity->screens() === []) {
                continue;
            }

            $this->line("## `{$parity->module()}`");
            $this->line('');

            foreach ($parity->screens() as $action => $elements) {
                $this->renderScreen($parity, (string) $action, $elements);
            }
        }

        $this->line('Status: ✅ ported · ⚠️ partial · 🚫 deferred (waiting on another feature) · ❌ missing');

        return self::SUCCESS;
    }

    /** @param list<ScreenElement> $elements */
    private function renderScreen(RouteParity $parity, string $action, array $elements): void
    {
        $map = $this->mapForAction($parity, $action);
        $route = $map?->laravelRoute;
        $uri = $route !== null ? Route::getRoutes()->getByName($route)?->uri() : null;
        $url = $uri !== null ? '/'.ltrim($uri, '/') : '(missing)';
        $bodyId = $route !== null ? $parity->bodyId($route) : null;

        $heading = $bodyId !== null ? "`{$bodyId}`" : "`{$parity->module()}` / `{$action}`";
        $this->line("### {$heading} — `{$route}` `{$url}`");
        $this->line('');
        $this->line('| status | element | level | OpenPNE 3 source | note |');
        $this->line('|---|---|---|---|---|');

        foreach ($elements as $element) {
            $note = $element->note ?? '';
            $this->line("| {$element->status->icon()} | {$element->name} | {$element->level->value} | `{$element->op3Source}` | {$note} |");
        }

        $this->line('');
        $this->line('Coverage: '.$this->coverage($elements));
        $this->line('');
    }

    private function mapForAction(RouteParity $parity, string $action): ?RouteMap
    {
        foreach ($parity->maps() as $map) {
            if ($map->op3Action === $action) {
                return $map;
            }
        }

        return null;
    }

    /** @param list<ScreenElement> $elements */
    private function coverage(array $elements): string
    {
        $counts = [];
        foreach (ScreenStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($elements as $element) {
            $counts[$element->status->value]++;
        }

        return implode(' · ', array_map(
            static fn (ScreenStatus $status): string => $status->icon().' '.$counts[$status->value],
            ScreenStatus::cases(),
        ));
    }
}
