<?php

namespace Tests\Feature\Compat;

use App\Compat\Parities\DiaryRouteParity;
use App\Compat\Parities\MemberRouteParity;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
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
    public function test_each_screen_key_resolves_to_a_map_that_derives_a_body_id(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach (array_keys($parity->screens()) as $key) {
                $map = $parity->screenMap((string) $key);
                $this->assertNotNull($map,
                    "{$parity->module()}: screen `{$key}` resolves to no route map (action / module/action / Laravel route)");

                $this->assertNotNull($parity->bodyId($map->laravelRoute),
                    "{$parity->module()}: screen `{$key}` does not derive a body id");
            }
        }
    }

    public function test_screen_routes_are_registered(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach (array_keys($parity->screens()) as $key) {
                $map = $parity->screenMap((string) $key);
                $this->assertNotNull(Route::getRoutes()->getByName($map->laravelRoute),
                    "{$parity->module()}: screen `{$key}` route `{$map->laravelRoute}` is not registered");
            }
        }
    }

    public function test_screen_keys_resolve_by_module_not_declaration_order(): void
    {
        // diary declares its own deleteConfirm before the diaryComment one: a bare action must
        // stay within the parity's module, and module/action must reach the op3Module route.
        $diary = new DiaryRouteParity;
        $this->assertSame('diary.delete.show', $diary->screenMap('deleteConfirm')?->laravelRoute);
        $this->assertSame('diary.comment.delete.show', $diary->screenMap('diaryComment/deleteConfirm')?->laravelRoute);
        $this->assertSame('diary.comment.delete.show', $diary->screenMap('diary.comment.delete.show')?->laravelRoute);
        $this->assertNull($diary->screenMap('diaryComment/show'));

        // A route name needs no dot to be one.
        $this->assertSame('login', (new MemberRouteParity)->screenMap('login')?->laravelRoute);
    }

    public function test_screen_keys_never_resolve_to_a_post_submit(): void
    {
        // A submit may carry the op3Action of the page it re-renders (message.bulk → list), and
        // declaring it first must not make it the screen.
        $parity = new class extends RouteParity
        {
            protected string $module = 'message';

            public function maps(): array
            {
                return [
                    new RouteMap(null, null, 'message.bulk', 'POST', op3Action: 'list'),
                    new RouteMap('receiveList', '/message/receiveList', 'message.receive', 'GET', op3Action: 'list'),
                ];
            }
        };

        $this->assertSame('message.receive', $parity->screenMap('list')?->laravelRoute);
        $this->assertNull($parity->screenMap('message.bulk'));

        foreach (RouteParityRegistry::all() as $live) {
            foreach (array_keys($live->screens()) as $key) {
                $this->assertSame('GET', $live->screenMap((string) $key)?->method,
                    "{$live->module()}: screen `{$key}` resolves to a non-GET map");
            }
        }
    }

    /**
     * Held in the other direction from the key checks above: every GET map rendering a Classic
     * screen must have a screens() entry, native screens exempted by name with a reason.
     */
    public function test_every_classic_get_screen_has_an_inventory(): void
    {
        $native = [
            'page_friend_requests' => 'the pending-request queues; OpenPNE 3 answered requests from the notification center',
        ];

        foreach (RouteParityRegistry::all() as $parity) {
            $inventoried = [];
            foreach (array_keys($parity->screens()) as $key) {
                $inventoried[$parity->bodyId($parity->screenMap((string) $key)->laravelRoute)] = true;
            }

            foreach ($parity->maps() as $map) {
                if ($map->method !== 'GET' || $map->op3Action === null || Route::getRoutes()->getByName($map->laravelRoute) === null) {
                    continue;
                }
                $bodyId = $parity->bodyId($map->laravelRoute);
                if (isset($native[$bodyId])) {
                    continue;
                }
                $this->assertArrayHasKey($bodyId, $inventoried,
                    "{$parity->module()}: `{$map->laravelRoute}` renders `{$bodyId}` but no screens() key inventories it");
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
        // Ported is the only status that may omit the note, so the note column is always a gap reason.
        $this->assertFalse(ScreenStatus::Ported->requiresNote());
    }
}
