<?php

namespace Tests\Feature\Compat;

use App\Compat\RouteParityRegistry;
use App\Compat\ScreenStatus;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Binds the screen-element inventory to reality. Like the route-parity audit, it checks
 * well-formedness and binding — not completion: a screen's action must be a real mapped route
 * that exists, an element short of a faithful port must say why, and every element must name
 * its OpenPNE 3 source so the inventory stays auditable against the template.
 */
class ScreenParityAuditTest extends TestCase
{
    public function test_each_screen_action_is_a_mapped_route_that_exists(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach (array_keys($parity->screens()) as $action) {
                $actions = array_map(static fn ($map) => $map->op3Action, $parity->maps());
                $this->assertContains($action, $actions,
                    "{$parity->module()}: screen `{$action}` has no route map with that op3Action");

                $route = $parity->bodyId(
                    // resolve the action's Laravel route via the matching map
                    collect($parity->maps())->firstWhere('op3Action', $action)->laravelRoute,
                );
                $this->assertNotNull($route,
                    "{$parity->module()}: screen `{$action}` does not derive a body id");
            }
        }
    }

    public function test_screen_routes_are_registered(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach (array_keys($parity->screens()) as $action) {
                $map = collect($parity->maps())->firstWhere('op3Action', $action);
                $this->assertNotNull(Route::getRoutes()->getByName($map->laravelRoute),
                    "{$parity->module()}: screen `{$action}` route `{$map->laravelRoute}` is not registered");
            }
        }
    }

    public function test_elements_are_well_formed(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->screens() as $action => $elements) {
                $this->assertNotEmpty($elements,
                    "{$parity->module()}: screen `{$action}` declares no elements");

                foreach ($elements as $element) {
                    $this->assertNotSame('', trim($element->op3Source),
                        "{$parity->module()}/{$action}: element `{$element->name}` must name its OpenPNE 3 source");

                    if ($element->status->requiresNote()) {
                        $this->assertNotSame('', trim((string) $element->note),
                            "{$parity->module()}/{$action}: `{$element->name}` is {$element->status->value} and must record a reason");
                    }
                }
            }
        }
    }

    public function test_ported_elements_need_no_note(): void
    {
        // The flip side: a faithful port carries no reason, so Ported is the only status that
        // may omit the note. Keeps the note column meaningful (it is always a gap reason).
        $this->assertFalse(ScreenStatus::Ported->requiresNote());
    }
}
